#!/usr/bin/env bash
# Update the ENRICHMENT_LOGIC_VERSION constant in Plugin.php.
#
# This constant is mixed into the per-file enrichment state hash. Bumping it
# on every release invalidates cached enrichment state, so users get the new
# behaviour on the next run without manually deleting state files.
#
# Usage: bash scripts/bump-logic-version.sh <version-tag>
#   e.g. bash scripts/bump-logic-version.sh v1.7.2
#
# Resulting constant value: YYYY.MM.DD-<tag>  (UTC date)

set -euo pipefail

TAG="${1:-}"
if [[ -z "$TAG" ]]; then
    echo "Usage: $0 <version-tag>" >&2
    exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$ROOT_DIR/Plugin.php"

NEW_LOGIC_VERSION="$(date -u +%Y.%m.%d)-${TAG}"
echo "Setting ENRICHMENT_LOGIC_VERSION = '$NEW_LOGIC_VERSION'"

NEW_LOGIC_VERSION="$NEW_LOGIC_VERSION" TARGET="$TARGET" python3 - <<'PYEOF'
import os, re, pathlib
v = os.environ['NEW_LOGIC_VERSION']
p = pathlib.Path(os.environ['TARGET'])
src = p.read_text()
new, n = re.subn(
    r"(private const ENRICHMENT_LOGIC_VERSION\s*=\s*)'[^']*'(\s*;)",
    lambda m: f"{m.group(1)}'{v}'{m.group(2)}",
    src,
    count=1,
)
if n == 0:
    raise SystemExit("ERROR: ENRICHMENT_LOGIC_VERSION constant not found in Plugin.php")
p.write_text(new)
PYEOF

grep -n "ENRICHMENT_LOGIC_VERSION = '$NEW_LOGIC_VERSION'" "$TARGET" \
    || { echo "ERROR: bump did not land" >&2; exit 1; }
php -l "$TARGET" >/dev/null
echo "OK"
