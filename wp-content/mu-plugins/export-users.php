<?php
/**
 * Plugin Name: Bulk User Export
 * Description: Export large number of WordPress users
 * Author: pavel.chesnovsky@gmail.com
 * Version: 1.0
 */

if (!defined('ABSPATH')) {
    exit;
}


class Bulk_User_Export {
    private $batch_size = 50; // Reduce batch size even more
    private $export_file;
    private $export_file_key = 'bulk_user_export_file';
    private $lock_key = 'bulk_user_export_lock';
    private $lock_timeout = 30;
    private $max_retries = 3;
    private $cancel_key = 'bulk_user_export_cancel';
    private $selected_role_key = 'bulk_user_export_role';
    private $date_from_key = 'bulk_user_export_date_from';
    private $date_to_key = 'bulk_user_export_date_to';
    private $state_key = 'bulk_user_export_state';
    private $status_key = 'bulk_user_export_status';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_export_users_batch', array($this, 'process_export_batch'));
        add_action('wp_ajax_cancel_user_export', array($this, 'cancel_export'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function enqueue_scripts($hook) {
        if ($hook != 'tools_page_bulk-user-export') {
            return;
        }
        wp_enqueue_script('jquery');
    }
    
    public function add_admin_menu() {
        add_management_page(
            'User Export',
            'User Export',
            'manage_options',
            'bulk-user-export',
            array($this, 'render_admin_page')
        );
    }
    
    private function get_available_roles() {
        global $wp_roles;
        
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        
        $roles = array();
        foreach ($wp_roles->get_names() as $role_key => $role_name) {
            // Skip roles that are not relevant for export
            // if (in_array($role_key, array('administrator', 'fabricator', 'subscriber', 'specifier'))) {
                $roles[$role_key] = $role_name;
            // }
        }
        
        return $roles;
    }
    
    private function get_available_states() {
        global $wpdb;
        
        $states = $wpdb->get_col("
            SELECT DISTINCT meta_value 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'registration_state' 
            AND meta_value != '' 
            ORDER BY meta_value ASC
        ");
        
        return array_combine($states, $states);
    }
    
    private function get_available_statuses() {
        global $wpdb;
        
        $statuses = $wpdb->get_col("
            SELECT DISTINCT meta_value 
            FROM {$wpdb->usermeta} 
            WHERE meta_key = 'account_status' 
            AND meta_value != '' 
            ORDER BY meta_value ASC
        ");
        
        // Маппинг статусов на читабельные названия
        $status_labels = array(
            'approved' => 'Approved',
            'pending' => 'Pending review',
            'rejected' => 'Membership rejected',
            'inactive' => 'Membership inactive',
            'blocked' => 'Blocked',
            'unverified' => 'Unverified',
            'awaiting_admin_review' => 'Pending administrator review',
            'awaiting_email_confirmation' => 'Waiting email confirmation',
            'awaiting_payment' => 'Awaiting Payment',
            'expired' => 'Expired',
            'suspended' => 'Suspended',
            'deleted' => 'Deleted'
        );
        
        $formatted_statuses = array();
        foreach ($statuses as $status) {
            $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst(str_replace('_', ' ', $status));
            $formatted_statuses[$status] = $label;
        }
        
        return $formatted_statuses;
    }
    
    public function render_admin_page() {
        $roles = $this->get_available_roles();
        $states = $this->get_available_states();
        $statuses = $this->get_available_statuses();
        ?>
        <div class="wrap">
            <h1>User Export</h1>
            <div id="export-settings" style="margin-bottom: 20px;">
                <div style="margin-bottom: 15px;">
                    <label for="user-role" style="margin-right: 10px;">Select user role:</label>
                    <select id="user-role" name="user-role">
                        <option value="">All roles</option>
                        <?php foreach ($roles as $role_key => $role_name) : ?>
                            <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="state" style="margin-right: 10px;">Select state:</label>
                    <select id="state" name="state">
                        <option value="">All states</option>
                        <?php foreach ($states as $state_key => $state_name) : ?>
                            <option value="<?php echo esc_attr($state_key); ?>"><?php echo esc_html($state_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="status" style="margin-right: 10px;">Select account status:</label>
                    <select id="status" name="status" style="min-width: 200px;">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $status_key => $status_name) : ?>
                            <option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="date-from" style="margin-right: 10px;">Registration date from:</label>
                    <input type="date" id="date-from" name="date-from" style="margin-right: 20px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; width: 150px;">
                    <label for="date-to" style="margin-right: 10px;">to:</label>
                    <input type="date" id="date-to" name="date-to" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; width: 150px;">
                    <button type="button" id="clear-dates" class="button" style="margin-left: 10px;">Clear dates</button>
                </div>
            </div>
            <div id="export-progress" style="display: none;">
                <p>Export Progress: <span id="progress">0</span>%</p>
                <div class="progress-bar" style="width: 100%; height: 20px; background: #f0f0f0;">
                    <div id="progress-bar-inner" style="width: 0%; height: 100%; background: #0073aa;"></div>
                </div>
                <p id="current-batch"></p>
                <p id="time-info" style="margin-top: 10px; color: #666;"></p>
                <p id="total-users" style="margin-top: 10px; font-weight: bold;"></p>
                <button id="cancel-export" class="button button-secondary" style="display: none; margin-bottom:20px;">Cancel Export</button>
            </div>
            <div id="progress-container"></div>
            <button id="start-export" class="button button-primary">Start Export</button>
            <div id="export-status"></div>
        </div>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var isProcessing = false;
            var retryCount = 0;
            var lastOffset = 0;
            var total_users = 0;
            var startTime = null;
            var exportRole = '';
            var exportState = '';
            var exportStatus = '';
            var exportDateFrom = '';
            var exportDateTo = '';
            
            function formatTime(seconds) {
                if (seconds < 60) {
                    return seconds + ' seconds';
                } else if (seconds < 3600) {
                    var minutes = Math.floor(seconds / 60);
                    var remainingSeconds = seconds % 60;
                    return minutes + ' min ' + remainingSeconds + ' sec';
                } else {
                    var hours = Math.floor(seconds / 3600);
                    var minutes = Math.floor((seconds % 3600) / 60);
                    return hours + ' hour' + (hours !== 1 ? 's' : '') + ' ' + minutes + ' min';
                }
            }
            
            function updateTimeInfo(processed, total) {
                if (!startTime) return;
                
                var currentTime = new Date();
                var elapsedSeconds = Math.floor((currentTime - startTime) / 1000);
                var progress = processed / total;
                var estimatedTotalSeconds = Math.floor(elapsedSeconds / progress);
                var remainingSeconds = estimatedTotalSeconds - elapsedSeconds;
                
                var timeInfo = 'Current time: ' + formatTime(elapsedSeconds);
                if (remainingSeconds > 0) {
                    timeInfo += ' | Estimated time remaining: ' + formatTime(remainingSeconds);
                }
                
                $('#time-info').text(timeInfo);
            }
            
            // Clear dates button functionality
            $('#clear-dates').on('click', function() {
                $('#date-from').val('');
                $('#date-to').val('');
            });

            // Date validation
            $('#date-from, #date-to').on('change', function() {
                var fromDate = $('#date-from').val();
                var toDate = $('#date-to').val();
                
                if (fromDate && toDate) {
                    if (new Date(fromDate) > new Date(toDate)) {
                        alert('Invalid date range: "From" date cannot be later than "To" date');
                        $(this).val('');
                    }
                }
            });
            
            $('#start-export').on('click', function() {
                if (isProcessing) return;
                
                var selectedRole = $('#user-role').val();
                var selectedState = $('#state').val();
                var selectedStatus = $('#status').val();
                var dateFrom = $('#date-from').val();
                var dateTo = $('#date-to').val();
                exportRole = selectedRole;
                exportState = selectedState;
                exportStatus = selectedStatus;
                exportDateFrom = dateFrom;
                exportDateTo = dateTo;
                
                // Validate dates only if both are filled
                if (dateFrom && dateTo) {
                    if (new Date(dateFrom) > new Date(dateTo)) {
                        $('#export-status').html('<p class="error">Invalid date range: "From" date cannot be later than "To" date</p>');
                        return;
                    }
                }
                
                // Reset progress data
                $('#progress').text('0');
                $('#progress-bar-inner').css('width', '0%');
                $('#current-batch').text('');
                $('#total-users').text('');
                $('#progress-container').html('');
                $('#export-status').html('');
                $('#time-info').text('');
                total_users = 0;
                startTime = new Date();
                
                $(this).prop('disabled', true);
                $('#export-progress').show();
                $('#cancel-export').show();
                isProcessing = true;
                retryCount = 0;
                processBatch(0, selectedRole, selectedState, selectedStatus, dateFrom, dateTo);
            });
            
            $('#cancel-export').on('click', function() {
                if (!isProcessing) return;
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'cancel_user_export',
                        nonce: '<?php echo wp_create_nonce("export_users_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            isProcessing = false;
                            $('#export-status').html('<p class="error">Export cancelled</p>');
                            $('#start-export').prop('disabled', false);
                            $('#cancel-export').hide();
                        }
                    }
                });
            });
            
            function processBatch(offset, role, state, status, dateFrom, dateTo) {
                if (!isProcessing) return;
                
                lastOffset = offset;
                var startNumber = offset + 1;
                var endNumber = Math.min(offset + 50, total_users);
                $('#current-batch').text('Processing users from ' + startNumber + ' to ' + endNumber);
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'export_users_batch',
                        offset: offset,
                        role: role,
                        state: state,
                        status: status,
                        date_from: dateFrom,
                        date_to: dateTo,
                        nonce: '<?php echo wp_create_nonce("export_users_nonce"); ?>'
                    },
                    success: function(response) {
                        console.log('Server response:', response);
                        if (response.success) {
                            retryCount = 0;
                            
                            if (response.data.no_users) {
                                console.log('No users found with filters');
                                $('#progress').text('0');
                                $('#progress-bar-inner').css('width', '0%');
                                $('#current-batch').text(response.data.message);
                                $('#total-users').text('Total users: 0');
                                $('#time-info').text('');
                                $('#start-export').prop('disabled', false);
                                $('#cancel-export').hide();
                                $('#export-progress').hide();
                                isProcessing = false;
                                return;
                            }
                            
                            total_users = response.data.total;
                            var progress = Math.min(100, Math.round((response.data.processed / response.data.total) * 100));
                            $('#progress').text(progress);
                            $('#progress-bar-inner').css('width', progress + '%');
                            $('#total-users').text('Total users: ' + response.data.total);
                            
                            updateTimeInfo(response.data.processed, response.data.total);
                            
                            if (response.data.completed) {
                                $('#progress-bar-inner').css('width', '100%');
                                $('#progress-status').text('Export completed!');
                                $('#current-batch').text('Total exported: ' + response.data.processed + ' users');
                                
                                console.log('Export completed response:', response.data);
                                
                                if (response.data.file_url) {
                                    console.log('File URL:', response.data.file_url);
                                    console.log('File size:', response.data.file_size);
                                    
                                    // Create container for download link
                                    const downloadContainer = $('<div>')
                                        .css({
                                            'margin-top': '20px',
                                            'margin-bottom': '20px',
                                            'padding': '15px',
                                            'background': '#f8f9fa',
                                            'border': '1px solid #ddd',
                                            'border-radius': '4px'
                                        });
                                    
                                    // Add header
                                    downloadContainer.append(
                                        $('<h3>').text('Export file is ready')
                                    );
                                    
                                    // Add file information
                                    downloadContainer.append(
                                        $('<p>').html('File size: <strong>' + (response.data.file_size / 1024).toFixed(2) + ' KB</strong>')
                                    );
                                    
                                    // Add time information
                                    if (startTime) {
                                        var totalTime = Math.floor((new Date() - startTime) / 1000);
                                        downloadContainer.append(
                                            $('<p>').html('Total export time: <strong>' + formatTime(totalTime) + '</strong>')
                                        );
                                    }
                                    
                                    // Add direct download link
                                    downloadContainer.append(
                                        $('<p>').html('Download link: <a href="' + response.data.file_url + '" target="_blank">' + response.data.file_url + '</a>')
                                    );
                                    
                                    // Add download button
                                    const downloadButton = $('<button>')
                                        .text('Download File')
                                        .addClass('button button-primary')
                                        .css('margin-top', '10px')
                                        .on('click', function(e) {
                                            e.preventDefault();
                                            window.open(response.data.file_url, '_blank');
                                        });
                                    
                                    downloadContainer.append(downloadButton);
                                    $('#progress-container').html(downloadContainer);
                                } else {
                                    console.error('File URL is missing in response:', response.data);
                                    $('#export-status').html('<p class="error">Error: File URL not received. Please check server logs for details.</p>');
                                }
                                
                                $('#start-export').prop('disabled', false);
                                $('#cancel-export').hide();
                                isProcessing = false;
                            } else {
                                setTimeout(function() {
                                    processBatch(response.data.next_offset, role, state, status, dateFrom, dateTo);
                                }, 200);
                            }
                        } else {
                            handleError(response.data.message);
                        }
                    },
                    error: function() {
                        handleError('Server error');
                    }
                });
            }
            
            function handleError(message) {
                if (retryCount < <?php echo $this->max_retries; ?>) {
                    retryCount++;
                    $('#export-status').html('<p class="error">Attempt ' + retryCount + ' of <?php echo $this->max_retries; ?>: ' + message + '</p>');
                    setTimeout(function() {
                        processBatch(lastOffset, exportRole, exportState, exportStatus, exportDateFrom, exportDateTo);
                    }, 2000);
                } else {
                    $('#export-status').html('<p class="error">Error: ' + message + '<br>Please try starting the export again.</p>');
                    $('#start-export').prop('disabled', false);
                    $('#cancel-export').hide();
                    isProcessing = false;
                }
            }
        });
        </script>
        <?php
    }
    
    public function cancel_export() {
        check_ajax_referer('export_users_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        update_option($this->cancel_key, true);
        $this->cleanup_export();
        
        wp_send_json_success();
    }
    
    private function cleanup_export() {
        delete_option($this->export_file_key);
        delete_option('bulk_export_total_users');
        delete_option($this->cancel_key);
        delete_option($this->selected_role_key);
        delete_option($this->state_key);
        delete_option($this->status_key);
        delete_option($this->date_from_key);
        delete_option($this->date_to_key);
        $this->release_lock();
    }
    
    private function acquire_lock() {
        $lock = get_option($this->lock_key);
        if ($lock && (time() - $lock) < $this->lock_timeout) {
            // Если блокировка существует, но процесс экспорта не активен, снимаем блокировку
            if (!get_option($this->export_file_key)) {
                $this->release_lock();
                return true;
            }
            return false;
        }
        update_option($this->lock_key, time());
        return true;
    }
    
    private function release_lock() {
        delete_option($this->lock_key);
    }
    
    public function process_export_batch() {
        try {
            check_ajax_referer('export_users_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }
            
            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
            $state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : '';
            $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
            $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
            $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
            
            error_log('Export request - Offset: ' . $offset . ', Role: ' . $role . ', State: ' . $state . ', Status: ' . $status . ', Date from: ' . $date_from . ', Date to: ' . $date_to);
            
            global $wpdb;
            
            // Формируем базовые условия WHERE
            $where_conditions = array();
            $where_params = array();
            
            if (!empty($role)) {
                $where_conditions[] = "EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} 
                    WHERE user_id = {$wpdb->users}.id 
                    AND meta_key = '{$wpdb->prefix}capabilities' 
                    AND meta_value LIKE %s
                )";
                $where_params[] = '%' . $wpdb->esc_like($role) . '%';
            }
            
            if (!empty($state)) {
                $where_conditions[] = "EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} 
                    WHERE user_id = {$wpdb->users}.id 
                    AND meta_key = 'registration_state' 
                    AND meta_value = %s
                )";
                $where_params[] = $state;
            }
            
            if (!empty($status)) {
                $where_conditions[] = "EXISTS (
                    SELECT 1 FROM {$wpdb->usermeta} 
                    WHERE user_id = {$wpdb->users}.id 
                    AND meta_key = 'account_status' 
                    AND meta_value = %s
                )";
                $where_params[] = $status;
            }
            
            if (!empty($date_from) && !empty($date_to)) {
                $where_conditions[] = "(
                    user_registered >= %s AND user_registered <= %s
                    OR {$wpdb->users}.id IN (
                        SELECT user_id FROM {$wpdb->usermeta}
                        WHERE meta_key = 'registration_create_time'
                        AND meta_value >= %s
                        AND meta_value <= %s
                    )
                )";
                $where_params[] = $date_from . ' 00:00:00';
                $where_params[] = $date_to . ' 23:59:59';
                $where_params[] = $date_from . ' 00:00:00';
                $where_params[] = $date_to . ' 23:59:59';
            } elseif (!empty($date_from)) {
                $where_conditions[] = "(
                    user_registered >= %s
                    OR {$wpdb->users}.id IN (
                        SELECT user_id FROM {$wpdb->usermeta}
                        WHERE meta_key = 'registration_create_time'
                        AND meta_value >= %s
                    )
                )";
                $where_params[] = $date_from . ' 00:00:00';
                $where_params[] = $date_from . ' 00:00:00';
            } elseif (!empty($date_to)) {
                $where_conditions[] = "(
                    user_registered <= %s
                    OR {$wpdb->users}.id IN (
                        SELECT user_id FROM {$wpdb->usermeta}
                        WHERE meta_key = 'registration_create_time'
                        AND meta_value <= %s
                    )
                )";
                $where_params[] = $date_to . ' 23:59:59';
                $where_params[] = $date_to . ' 23:59:59';
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // Проверяем общее количество пользователей при первом запросе
            if ($offset === 0) {
                if (!empty($where_params)) {
                    $query = $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->users} {$where_clause}",
                        $where_params
                    );
                } else {
                    $query = "SELECT COUNT(*) FROM {$wpdb->users}";
                }

                error_log('[EXPORT] COUNT query: ' . $query);
                $start_time = microtime(true);
                $total_users = $wpdb->get_var($query);
                $elapsed = round((microtime(true) - $start_time) * 1000);
                error_log('[EXPORT] COUNT result: ' . $total_users . ' (took ' . $elapsed . 'ms)');
                
                if (empty($total_users)) {
                    error_log('[EXPORT] No users found with current filters. Role: ' . $role . ', State: ' . $state . ', Status: ' . $status . ', Date from: ' . $date_from . ', Date to: ' . $date_to);
                    $this->cleanup_export();
                    wp_send_json_success(array(
                        'no_users' => true,
                        'message' => 'No users found with selected filters'
                    ));
                    return;
                }
                
                update_option('bulk_export_total_users', $total_users);
                error_log('Saved total users count: ' . $total_users);
                
                // Создаем файл экспорта
                $upload_dir = wp_upload_dir();
                if (!is_writable($upload_dir['path'])) {
                    throw new Exception('Upload directory is not writable');
                }
                
                $this->export_file = $upload_dir['path'] . '/users-export-' . date('Y-m-d-H-i-s') . '.csv';
                update_option($this->export_file_key, $this->export_file);
                
                if (($fh = fopen($this->export_file, 'w')) === false) {
                    throw new Exception('Failed to create export file');
                }
                fputcsv($fh, array('ID', 'Email', 'Registration Date', 'Registration Create Time', 'Last Login', 'Account Status', 'Phone', 'Occupation', 'Role', 'Company', 'Registration ID', 'Full Name', 'Address'));
                fclose($fh);
            } else {
                $total_users = get_option('bulk_export_total_users', 0);
                $this->export_file = get_option($this->export_file_key);
                if (empty($this->export_file)) {
                    throw new Exception('Export file not found');
                }
            }
            
            // Проверяем отмену экспорта
            if (get_option($this->cancel_key)) {
                error_log('Export was cancelled');
                $this->cleanup_export();
                throw new Exception('Export was cancelled');
            }
            
            // Query 1: Get filtered user IDs only (no JOIN, fast)
            if (!empty($where_params)) {
                $id_query = $wpdb->prepare(
                    "SELECT id FROM {$wpdb->users} {$where_clause} ORDER BY id LIMIT %d OFFSET %d",
                    array_merge($where_params, array($this->batch_size, $offset))
                );
            } else {
                $id_query = $wpdb->prepare(
                    "SELECT id FROM {$wpdb->users} ORDER BY id LIMIT %d OFFSET %d",
                    array($this->batch_size, $offset)
                );
            }

            error_log('[EXPORT] ID query (offset=' . $offset . '): ' . $id_query);
            $start_time = microtime(true);
            $user_ids = $wpdb->get_col($id_query);
            $elapsed = round((microtime(true) - $start_time) * 1000);
            error_log('[EXPORT] ID query returned ' . count($user_ids) . ' IDs (took ' . $elapsed . 'ms)');

            if (empty($user_ids)) {
                $upload_dir = wp_upload_dir();
                error_log('Upload directory: ' . print_r($upload_dir, true));
                error_log('Export file path: ' . $this->export_file);

                if (!file_exists($this->export_file)) {
                    error_log('Export file does not exist: ' . $this->export_file);
                    throw new Exception('Export file not found on server');
                }

                $file_size = filesize($this->export_file);
                if ($file_size === 0) {
                    error_log('Export file is empty: ' . $this->export_file);
                    throw new Exception('Export file is empty');
                }

                // Формируем URL файла
                $file_url = str_replace(
                    array($upload_dir['basedir'], '\\'),
                    array($upload_dir['baseurl'], '/'),
                    $this->export_file
                );

                error_log('Generated file URL: ' . $file_url);
                error_log('File size: ' . $file_size);

                $this->release_lock();

                wp_send_json_success(array(
                    'completed' => true,
                    'processed' => $total_users,
                    'total' => $total_users,
                    'file_url' => $file_url,
                    'file_size' => $file_size
                ));

                return;
            }

            // Query 2: Get metadata for those IDs only (700 rows instead of 5,000+)
            $meta_keys = array(
                'registration_create_time', '_um_last_login', 'account_status',
                'registration_mobile', 'registration_what_are_you', "{$wpdb->prefix}capabilities",
                'registration_company', 'registration_id', 'first_name', 'last_name',
                'registration_street', 'registration_suburb', 'registration_city', 'registration_state', 'registration_postcode'
            );
            $id_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));

            $data_query = $wpdb->prepare(
                "SELECT wp_users.id, wp_users.user_email, wp_users.user_registered,
                MAX(CASE WHEN wp_usermeta.meta_key = 'registration_create_time' THEN wp_usermeta.meta_value END) AS Registration_create_time,
                MAX(CASE WHEN wp_usermeta.meta_key = '_um_last_login' THEN wp_usermeta.meta_value END) AS Last_login,
                MAX(CASE WHEN wp_usermeta.meta_key = 'account_status' THEN wp_usermeta.meta_value END) AS Account_status,
                MAX(CASE WHEN wp_usermeta.meta_key = 'registration_mobile' THEN wp_usermeta.meta_value END) AS Phone,
                MAX(CASE WHEN wp_usermeta.meta_key = 'registration_what_are_you' THEN wp_usermeta.meta_value END) AS Occupation,
                MAX(CASE WHEN wp_usermeta.meta_key = '{$wpdb->prefix}capabilities' THEN
                    CASE WHEN POSITION('administrator' IN wp_usermeta.meta_value) > 0 THEN 'Administrator'
                        WHEN POSITION('fabricator' IN wp_usermeta.meta_value) > 0 THEN 'Fabricator'
                        WHEN POSITION('subscriber' IN wp_usermeta.meta_value) > 0 THEN 'Subscriber'
                        WHEN POSITION('specifier' IN wp_usermeta.meta_value) > 0 THEN 'Specifier'
                    ELSE wp_usermeta.meta_value
                    END
                END) AS Capability,
                MAX(CASE WHEN wp_usermeta.meta_key = 'registration_company' THEN wp_usermeta.meta_value END) AS Company,
                MAX(CASE WHEN wp_usermeta.meta_key = 'registration_id' THEN wp_usermeta.meta_value END) AS Registration_ID,
                CONCAT(MAX(CASE WHEN wp_usermeta.meta_key = 'first_name' THEN wp_usermeta.meta_value END), ' ', MAX(CASE WHEN wp_usermeta.meta_key = 'last_name' THEN wp_usermeta.meta_value END)) AS full_name,
                CONCAT_WS(', ',
                    MAX(CASE WHEN wp_usermeta.meta_key = 'registration_street' THEN wp_usermeta.meta_value END),
                    MAX(CASE WHEN wp_usermeta.meta_key = 'registration_suburb' THEN wp_usermeta.meta_value END),
                    MAX(CASE WHEN wp_usermeta.meta_key = 'registration_city' THEN wp_usermeta.meta_value END),
                    MAX(CASE WHEN wp_usermeta.meta_key = 'registration_state' THEN wp_usermeta.meta_value END),
                    MAX(CASE WHEN wp_usermeta.meta_key = 'registration_postcode' THEN wp_usermeta.meta_value END)
                ) AS registration_address
                FROM {$wpdb->users} wp_users
                INNER JOIN {$wpdb->usermeta} wp_usermeta ON wp_users.id = wp_usermeta.user_id
                    AND wp_usermeta.meta_key IN ({$meta_placeholders})
                WHERE wp_users.id IN ({$id_placeholders})
                GROUP BY wp_users.id, wp_users.user_email
                ORDER BY wp_users.id",
                array_merge($meta_keys, $user_ids)
            );

            error_log('[EXPORT] DATA query (offset=' . $offset . '): ' . $data_query);
            $start_time = microtime(true);
            $users = $wpdb->get_results($data_query);
            $elapsed = round((microtime(true) - $start_time) * 1000);
            error_log('[EXPORT] DATA query returned ' . count($users) . ' rows (took ' . $elapsed . 'ms)');
            
            if (($fh = fopen($this->export_file, 'a')) === false) {
                throw new Exception('Failed to open export file for writing');
            }
            foreach ($users as $user) {
                // Fall back to registration_create_time for MODX-migrated users with empty user_registered
                $reg_date = (!empty($user->user_registered) && $user->user_registered !== '0000-00-00 00:00:00')
                    ? $user->user_registered
                    : $user->Registration_create_time;
                fputcsv($fh, array(
                    $user->id,
                    $user->user_email,
                    $reg_date,
                    $user->Registration_create_time,
                    $user->Last_login,
                    $user->Account_status,
                    $user->Phone,
                    $user->Occupation,
                    $user->Capability,
                    $user->Company,
                    $user->Registration_ID,
                    $user->full_name,
                    $user->registration_address
                ));
            }
            fclose($fh);
            
            $processed_count = $offset + count($users);
            
            // Если это последняя порция данных, отправляем информацию о файле
            if ($processed_count >= $total_users) {
                $upload_dir = wp_upload_dir();
                $file_url = str_replace(
                    array($upload_dir['basedir'], '\\'),
                    array($upload_dir['baseurl'], '/'),
                    $this->export_file
                );
                $file_size = filesize($this->export_file);
                
                $this->release_lock();
                
                wp_send_json_success(array(
                    'completed' => true,
                    'processed' => $processed_count,
                    'total' => $total_users,
                    'file_url' => $file_url,
                    'file_size' => $file_size
                ));
            } else {
                wp_send_json_success(array(
                    'completed' => false,
                    'processed' => $processed_count,
                    'total' => $total_users,
                    'next_offset' => $processed_count
                ));
            }
            
        } catch (Exception $e) {
            $this->cleanup_export();
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}

new Bulk_User_Export(); 