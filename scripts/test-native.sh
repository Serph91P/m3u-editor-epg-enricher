#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
host=${ENRICHER_HOST_ROOT:-}
if [[ -z "$host" || ! -f "$host/vendor/autoload.php" || ! -f "$host/tests/TestCase.php" || ! -f "$host/vendor/bin/pest" ]]; then
    printf '%s\n' 'ENRICHER_HOST_ROOT must point to an installed, isolated Laravel host.' >&2
    exit 2
fi
host=$(cd "$host" && pwd)
export ENRICHER_HOST_ROOT="$host"
unset EPG_ENRICHER_SOURCE
scratch=$(mktemp -d "${TMPDIR:?TMPDIR must be set}/enricher-native.XXXXXXXX")
trap 'rm -rf "$scratch"' EXIT
touch "$scratch/.env"
export ENRICHER_NATIVE_SCRATCH="$scratch"
export APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_URL=http://localhost
export APP_CONFIG_CACHE="$scratch/config.php" APP_ROUTES_CACHE="$scratch/routes.php"
export CACHE_STORE=array CACHE_LIMITER=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite_testing DB_DATABASE=:memory: DB_URL= LOG_CHANNEL=null
export TELESCOPE_ENABLED=false PULSE_ENABLED=false BCRYPT_ROUNDS=4 MAIL_MAILER=array BROADCAST_CONNECTION=null
relative=$(realpath --relative-to="$host" "$root/tests/native")
cd "$host"
php vendor/bin/pest --test-directory="$relative" --configuration="$root/tests/native/phpunit.xml" --fail-on-empty-test-suite --fail-on-skipped --fail-on-warning --do-not-cache-result "$@"
