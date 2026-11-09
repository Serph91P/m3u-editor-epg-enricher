<?php

namespace AppLocalPlugins\EpgEnricher\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/PreservedPrimaryKey.php';

class PreservedPrimaryRepository
{
    public const TABLE = 'plugin_epg_enricher_preserved_primary';

    public function supported(): bool
    {
        if (! class_exists(Schema::class) || ! Schema::hasTable(self::TABLE)) {
            if (class_exists(Log::class)) {
                Log::warning('EPG Enricher primary protection unsupported: plugin schema is not installed.');
            }
            return false;
        }
        return true;
    }

    public static function validUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    public static function overwritten(array $settings): bool
    {
        return (bool) (($settings['overwrite_existing'] ?? false) || ($settings['replace_existing_artwork'] ?? false));
    }

    /** @return array{key: string, stop: int}|null */
    public static function slot(int $epgId, array $programme): ?array
    {
        if ($epgId <= 0 || ! is_string($programme['channel'] ?? null)
            || empty($programme['start']) || empty($programme['stop'])) {
            return null;
        }
        try {
            $start = CarbonImmutable::parse($programme['start'])->timestamp;
            $stop = CarbonImmutable::parse($programme['stop'])->timestamp;
            if ($start >= $stop) { return null; }
            return ['key' => PreservedPrimaryKey::slot($epgId, $programme['channel'], $start, $stop), 'stop' => $stop];
        } catch (\Throwable) {
            return null;
        }
    }

    public function reconcile(int $epgId, array $before, array $current, array $settings): string
    {
        if (! $this->supported()) { return 'unsupported'; }
        $old = self::slot($epgId, $before);
        $slot = self::slot($epgId, $current);
        if ($old !== null && ($slot === null || $slot['key'] !== $old['key'])) {
            $this->remove($epgId, $old['key']);
        }
        if ($slot === null) { return 'unprotected'; }
        $url = $current['icon'] ?? null;
        if (self::overwritten($settings) || ! self::validUrl($url) || $url !== ($before['icon'] ?? null)) {
            $this->remove($epgId, $slot['key']);
            return 'unprotected';
        }
        $now = now();
        DB::table(self::TABLE)->upsert([[
            'epg_id' => $epgId, 'programme_key' => $slot['key'],
            'identity_hash' => PreservedPrimaryKey::identity($current), 'url' => $url,
            'stop_timestamp' => $slot['stop'], 'created_at' => $now, 'updated_at' => $now,
        ]], ['epg_id', 'programme_key'], ['identity_hash', 'url', 'stop_timestamp', 'updated_at']);
        return 'protected';
    }

    public function remove(int $epgId, string $key): void
    {
        DB::table(self::TABLE)->where('epg_id', $epgId)->where('programme_key', $key)->delete();
    }

    // Callers must supply freshly read canonical data and current resolved settings.
    public function validated(int $epgId, array $current, array $settings): ?string
    {
        if (! $this->supported() || self::overwritten($settings)) { return null; }
        $slot = self::slot($epgId, $current);
        $url = $current['icon'] ?? null;
        if ($slot === null || $slot['stop'] <= time() || ! self::validUrl($url)) { return null; }
        $row = DB::table(self::TABLE)->where('epg_id', $epgId)->where('programme_key', $slot['key'])->first();
        return $row !== null && $row->url === $url && $row->identity_hash === PreservedPrimaryKey::identity($current)
            ? $url : null;
    }

    public function cleanup(): int
    {
        return $this->supported() ? DB::table(self::TABLE)->where('stop_timestamp', '<=', time())->delete() : 0;
    }
}
