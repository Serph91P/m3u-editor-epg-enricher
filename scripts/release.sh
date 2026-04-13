#!/usr/bin/env bash
set -euo pipefail

##############################################################################
# Release script for the EPG Enricher plugin.
#
# Usage:
#   bash scripts/release.sh patch    # 1.7.1 → 1.7.2
#   bash scripts/release.sh minor    # 1.7.1 → 1.8.0
#   bash scripts/release.sh major    # 1.7.1 → 2.0.0
#   bash scripts/release.sh 1.9.0   # explicit version
#
# What it does:
#   1. Validates the plugin (plugin.json + PHP syntax)
#   2. Bumps the version in plugin.json
#   3. Commits the version bump
#   4. Creates an annotated tag with changelog from commits since last tag
#   5. Pushes commit + tag (triggers GitHub Actions release workflow)
##############################################################################

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

MANIFEST="plugin.json"
BUMP_TYPE="${1:-}"

# ── Helpers ──────────────────────────────────────────────────────────────────

die()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "→ $*"; }

# ── Input validation ─────────────────────────────────────────────────────────

if [[ -z "$BUMP_TYPE" ]]; then
    echo "Usage: bash scripts/release.sh <patch|minor|major|X.Y.Z>"
    exit 1
fi

# Ensure working tree is clean (except plugin.json which we'll modify)
if [[ -n "$(git status --porcelain -- ':!plugin.json')" ]]; then
    die "Working tree has uncommitted changes. Commit or stash them first."
fi

# Ensure we're on main
CURRENT_BRANCH="$(git branch --show-current)"
if [[ "$CURRENT_BRANCH" != "main" ]]; then
    die "You're on '$CURRENT_BRANCH'. Switch to 'main' first."
fi

# Ensure main is up to date
git fetch origin --quiet
LOCAL_SHA="$(git rev-parse HEAD)"
REMOTE_SHA="$(git rev-parse origin/main 2>/dev/null || echo '')"
if [[ -n "$REMOTE_SHA" && "$LOCAL_SHA" != "$REMOTE_SHA" ]]; then
    die "Local main is not in sync with origin/main. Pull or push first."
fi

# ── Read current version ─────────────────────────────────────────────────────

CURRENT_VERSION="$(jq -r '.version' "$MANIFEST")"
if [[ -z "$CURRENT_VERSION" || "$CURRENT_VERSION" == "null" ]]; then
    die "Could not read version from $MANIFEST"
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

# ── Compute new version ──────────────────────────────────────────────────────

case "$BUMP_TYPE" in
    patch) NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))" ;;
    minor) NEW_VERSION="$MAJOR.$((MINOR + 1)).0" ;;
    major) NEW_VERSION="$((MAJOR + 1)).0.0" ;;
    *)
        # Explicit version — validate format
        if [[ ! "$BUMP_TYPE" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            die "Invalid version '$BUMP_TYPE'. Use patch, minor, major, or X.Y.Z."
        fi
        NEW_VERSION="$BUMP_TYPE"
        ;;
esac

TAG_NAME="v$NEW_VERSION"

# Check tag doesn't already exist
if git rev-parse "$TAG_NAME" >/dev/null 2>&1; then
    die "Tag $TAG_NAME already exists."
fi

info "Version: $CURRENT_VERSION → $NEW_VERSION ($TAG_NAME)"

# ── Validate plugin ─────────────────────────────────────────────────────────

info "Validating plugin..."

# JSON syntax
jq empty "$MANIFEST" || die "plugin.json is not valid JSON"

# PHP syntax
php -l Plugin.php > /dev/null || die "Plugin.php has syntax errors"

# Plugin validator script
if [[ -f scripts/validate-plugin.php ]]; then
    php scripts/validate-plugin.php || die "Plugin validation failed"
fi

info "Validation passed ✓"

# ── Build changelog from commits since last tag ──────────────────────────────

LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || echo '')"

if [[ -n "$LAST_TAG" ]]; then
    CHANGELOG="$(git log "${LAST_TAG}..HEAD" --pretty=format:'- %s' --no-merges)"
    COMPARE_URL="https://github.com/$(jq -r '.repository' "$MANIFEST")/compare/${LAST_TAG}...${TAG_NAME}"
else
    CHANGELOG="$(git log --pretty=format:'- %s' --no-merges)"
    COMPARE_URL=""
fi

if [[ -z "$CHANGELOG" ]]; then
    CHANGELOG="- No notable changes"
fi

# Build tag message
TAG_MESSAGE="$TAG_NAME

Changes since ${LAST_TAG:-initial}:
$CHANGELOG"

if [[ -n "$COMPARE_URL" ]]; then
    TAG_MESSAGE="$TAG_MESSAGE

Full diff: $COMPARE_URL"
fi

# ── Show summary and confirm ─────────────────────────────────────────────────

echo ""
echo "┌─────────────────────────────────────────────────"
echo "│ Release Summary"
echo "├─────────────────────────────────────────────────"
echo "│ Version: $CURRENT_VERSION → $NEW_VERSION"
echo "│ Tag:     $TAG_NAME"
echo "│"
echo "│ Changelog:"
echo "$CHANGELOG" | sed 's/^/│   /'
echo "└─────────────────────────────────────────────────"
echo ""
read -rp "Proceed with release? [y/N] " CONFIRM
if [[ "$CONFIRM" != [yY] ]]; then
    echo "Aborted."
    exit 0
fi

# ── Bump version in manifest ─────────────────────────────────────────────────

info "Bumping version in $MANIFEST..."

# Use jq to update version safely
TMPFILE="$(mktemp)"
jq --arg v "$NEW_VERSION" '.version = $v' "$MANIFEST" > "$TMPFILE"
mv "$TMPFILE" "$MANIFEST"

# ── Commit, tag, push ────────────────────────────────────────────────────────

info "Committing version bump..."
git add "$MANIFEST"
git commit -m "chore: bump version to $NEW_VERSION"

info "Creating tag $TAG_NAME..."
git tag -a "$TAG_NAME" -m "$TAG_MESSAGE"

info "Pushing to origin..."
git push origin main "$TAG_NAME"

echo ""
echo "✓ Released $TAG_NAME"
echo "  GitHub Actions will now create the release with artifacts."
echo "  https://github.com/$(jq -r '.repository' "$MANIFEST")/releases/tag/$TAG_NAME"
