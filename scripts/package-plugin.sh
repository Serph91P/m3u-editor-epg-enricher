#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
ARCHIVE_NAME="${1:-epg-enricher.zip}"
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE_NAME"
mkdir -p "$DIST_DIR"
cd "$ROOT_DIR"
rm -f "$ARCHIVE_PATH" "$ARCHIVE_PATH.sha256"
if command -v zip >/dev/null 2>&1; then
  zip "$ARCHIVE_PATH" Plugin.php plugin.json README.md Services/*.php
else
  php "$ROOT_DIR/scripts/package-plugin-fallback.php" "$ARCHIVE_PATH"
fi
php -r 'file_put_contents($argv[1].".sha256", hash_file("sha256", $argv[1]).PHP_EOL);' "$ARCHIVE_PATH"
printf 'Created %s\n' "$ARCHIVE_PATH"
