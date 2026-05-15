<?php
/**
 * Performance capture for QM audit.
 * Writes JSON per request to wp-content/qm-output/ when qm_auth cookie is set.
 * Runs at shutdown priority -1 to capture $wpdb->queries before QM clears them.
 * @todo Remove after audit is complete.
 */
defined('ABSPATH') || exit;

$GLOBALS['qm_audit_start'] = microtime(true);

// Grant 'view_query_monitor' cap when qm_auth cookie present.
// Prevents QM from firing qm/cease (which wipes $wpdb->queries after every query).
if (!empty($_COOKIE['qm_auth'])) {
    add_filter('user_has_cap', function ($allcaps, $caps) {
        if (in_array('view_query_monitor', $caps, true)) {
            $allcaps['view_query_monitor'] = true;
        }
        return $allcaps;
    }, 10, 2);
}

// Collect PHP errors for QM audit
$GLOBALS['_qm_audit_errors'] = [];

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return true;
    }
    $type_map = [
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
    ];
    $GLOBALS['_qm_audit_errors'][] = [
        'type'    => $type_map[$errno] ?? 'E_UNKNOWN',
        'message' => $errstr,
        'file'    => $errfile,
        'line'    => $errline,
    ];
    return false;
});

function qm_perf_capture_shutdown() {
    // Check 'qm_auth' first (audit crawl override), then fall back to QM_COOKIE constant
    $authorized = !empty($_COOKIE['qm_auth'])
        || (!empty($_COOKIE[defined('QM_COOKIE') ? QM_COOKIE : '']));
    if (!$authorized) {
        return;
    }

    global $wpdb;
    $output_dir = WP_CONTENT_DIR . '/qm-output';
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }

    $request_url = $_SERVER['REQUEST_URI'] ?? '/';
    $hash = substr(md5($request_url . microtime(true)), 0, 12);

    $query_count = 0;
    $total_query_time = 0;
    $slow_queries = [];
    $duplicate_queries = [];

    // Pattern histogram: normalized_prefix -> [count, total_time, sample_caller]
    $pattern_histogram = [];
    // Track individual option names from get_option queries
    $option_name_counts = [];
    $option_regex_misses = 0;
    $option_regex_hits = 0;

    // Read directly from $wpdb->queries (QM_DB enhanced format)
    // [0]=sql, [1]=time, [2]=caller, ['trace']=QM_Backtrace, ['result']=result
    if (is_array($wpdb->queries) && !empty($wpdb->queries)) {
        $query_count = count($wpdb->queries);
        $seen_queries = [];

        foreach ($wpdb->queries as $q) {
            $sql = $q[0] ?? '';
            $time = floatval($q[1] ?? 0);
            $caller = $q[2] ?? '';

            // QM_DB enhanced format
            if (isset($q['trace']) && method_exists($q['trace'], 'get_caller')) {
                $caller = $q['trace']->get_caller();
            }

            $total_query_time += $time;

            if ($time > 0.05) {
                $slow_queries[] = [
                    'sql' => substr($sql, 0, 500),
                    'time' => round($time, 4),
                    'caller' => $caller,
                ];
            }

            // Track option name queries
            if (stripos($sql, 'wp_options') !== false && stripos($sql, 'option_name') !== false) {
                if (preg_match("/option_name\s*=\s*'([^']+)'/", $sql, $om)) {
                    $opt_name = $om[1];
                    if (!isset($option_name_counts[$opt_name])) $option_name_counts[$opt_name] = 0;
                    $option_name_counts[$opt_name]++;
                    $option_regex_hits++;
                } else {
                    $option_regex_misses++;
                    if ($option_regex_misses <= 3) {
                        $option_name_counts['__MISS__' . $option_regex_misses] = substr($sql, 0, 200);
                    }
                }
            }

            // Build pattern histogram: normalize SQL, strip literals for grouping
            $normalized = preg_replace('/\s+/', ' ', trim($sql));
            // Replace numeric literals and quoted strings for pattern matching
            $pattern = preg_replace(
                ["/= '\d+'/", "/= \d+/", "/IN \(\d[^)]*\)/i", "/'[^']*'/", "/`\w+`\s*=\s*'[^']*'/"],
                ['= ?', '= ?', 'IN (?)', "'?'", '`col` = ?'],
                $normalized
            );
            $pattern = substr($pattern, 0, 200);

            if (!isset($pattern_histogram[$pattern])) {
                $pattern_histogram[$pattern] = ['count' => 0, 'total_time' => 0.0, 'sample_caller' => $caller, 'sample_sql' => substr($normalized, 0, 300), 'callers' => []];
            }
            $pattern_histogram[$pattern]['count']++;
            $pattern_histogram[$pattern]['total_time'] += $time;
            // Track caller diversity for top patterns (limit to avoid memory bloat)
            if ($pattern_histogram[$pattern]['count'] <= 5) {
                $pattern_histogram[$pattern]['callers'][] = $caller;
            }

            if (isset($seen_queries[$normalized])) {
                $duplicate_queries[$normalized] = ($duplicate_queries[$normalized] ?? 1) + 1;
            } else {
                $seen_queries[$normalized] = 1;
            }
        }
    }

    // Sort histogram by count desc, keep top 50
    uasort($pattern_histogram, fn($a, $b) => $b['count'] - $a['count']);
    $top_patterns = array_slice($pattern_histogram, 0, 50, true);
    // Round times
    foreach ($top_patterns as &$p) {
        $p['total_time'] = round($p['total_time'], 4);
    }
    unset($p);

    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    $start = $GLOBALS['qm_audit_start'] ?? $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    $total_time = microtime(true) - $start;

    // Capture fatal error if any
    $last_error = error_get_last();
    if ($last_error !== null && in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $GLOBALS['_qm_audit_errors'][] = [
            'type'    => 'E_FATAL',
            'message' => $last_error['message'],
            'file'    => $last_error['file'],
            'line'    => $last_error['line'],
        ];
    }

    $report = [
        'url' => $request_url,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'timestamp' => time(),
        'timings' => [
            'total' => round($total_time, 4),
            'db_queries' => round($total_query_time, 4),
        ],
        'db' => [
            'total_queries' => $query_count,
            'total_query_time' => round($total_query_time, 4),
            'duplicate_queries' => count($duplicate_queries),
            'duplicate_details' => array_slice($duplicate_queries, 0, 20),
            'slow_queries' => $slow_queries,
        ],
        'memory' => [
            'usage' => $memory_usage,
            'usage_mb' => round($memory_usage / 1048576, 2),
            'peak' => $memory_peak,
            'peak_mb' => round($memory_peak / 1048576, 2),
        ],
        'php_errors' => $GLOBALS['_qm_audit_errors'] ?? [],
        'top_slow_queries' => array_slice($slow_queries, 0, 10),
        'query_pattern_histogram' => $top_patterns,
        'option_regex_hits' => $option_regex_hits,
        'option_regex_misses' => $option_regex_misses,
        'option_queries_count' => count($option_name_counts),
        'option_queries' => (function() use ($option_name_counts) {
            arsort($option_name_counts);
            return $option_name_counts;
        })(),
    ];

    $filename = $output_dir . '/' . time() . '_' . $hash . '.json';
    file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
// Priority -1: before QM's dispatcher (priority 0) clears $wpdb->queries
add_action('shutdown', 'qm_perf_capture_shutdown', -1);
