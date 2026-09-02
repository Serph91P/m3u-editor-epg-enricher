#!/usr/bin/env bash
set -euo pipefail

normalize_subjects() {
  sed -E 's/^\[verified\][[:space:]]+//'
}

mode="${1:-}"
case "$mode" in
  normalize)
    normalize_subjects
    ;;
  classify)
    commits=$(normalize_subjects)
    bump="none"

    if printf '%s\n' "$commits" | grep -qiE '^[a-z]+(\(.+\))?!:|BREAKING CHANGE'; then
      bump="major"
    elif printf '%s\n' "$commits" | grep -qiE '^feat(\(.+\))?:'; then
      bump="minor"
    elif printf '%s\n' "$commits" | grep -qiE '^(fix|perf|refactor|style|build|ci|chore|docs|test)(\(.+\))?:'; then
      bump="patch"
    fi

    printf '%s\n' "$bump"
    ;;
  *)
    printf 'Usage: %s {normalize|classify}\n' "$0" >&2
    exit 64
    ;;
esac
