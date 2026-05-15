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

echo "Copying skills to .claude/skills/..."
mkdir -p .claude/skills
cp -r "$TMP/skills/"* .claude/skills/

echo "Copying tests/..."
cp -r "$TMP/tests" .

echo "Installing QM mu-plugin..."
mkdir -p wp-content/mu-plugins
cp "$TMP/skills/site-audit/mu-plugins/qm-perf-capture.php" wp-content/mu-plugins/

echo "Copying setup wizard and scripts..."
cp "$TMP/setup.py" .
mkdir -p scripts
cp -r "$TMP/scripts/"* scripts/ 2>/dev/null || true

# Run interactive setup (unless --no-setup)
if [[ "${1:-}" != "--no-setup" ]]; then
  echo ""
  echo "Starting interactive setup..."
  python3 setup.py
else
  echo ""
  echo "Skipped interactive setup. Run manually: python3 setup.py"
fi
