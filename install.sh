#!/usr/bin/env bash
# WP QA Skills installer
# Run from your WordPress project root:
#   bash <(curl -s https://raw.githubusercontent.com/damaverick/wp-qa-skills/main/install.sh)

set -euo pipefail

REPO="https://github.com/damaverick/wp-qa-skills.git"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# Sanity check — must run from a WP project root
if [ ! -d "wp-content" ]; then
  echo "Error: run this from your WordPress project root (wp-content/ not found)"
  exit 1
fi

echo "Cloning wp-qa-skills..."
git clone --depth=1 --quiet "$REPO" "$TMP"

echo "Copying skills/..."
cp -r "$TMP/skills" .

echo "Copying tests/..."
cp -r "$TMP/tests" .

echo "Installing QM mu-plugin..."
mkdir -p wp-content/mu-plugins
cp "$TMP/skills/site-audit/mu-plugins/qm-perf-capture.php" wp-content/mu-plugins/

echo ""
echo "Done. Next steps:"
echo "  1. Edit skills/site-audit/CONFIG.md  — set site URL, QM cookie, max_urls"
echo "  2. Edit skills/fix-bugherd/CONFIG.md — add BugHerd API key (optional)"
echo "  3. cd tests/playwright && npm install && npx playwright install chromium"
echo "  4. Install Query Monitor plugin in WordPress admin"
echo "  5. Open project in Claude Code and run /site-audit"
