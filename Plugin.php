<?php

namespace AppLocalPlugins\EpgEnricher;

use App\Models\Epg;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheService;
use App\Services\TmdbService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class Plugin implements EpgProcessorPluginInterface, HookablePluginInterface
{
    /**
     * Handle manual actions triggered from the plugin UI.
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'enrich_epg' => $this->enrichEpg($payload, $context),
            'health_check' => $this->healthCheck($context),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    /**
     * Handle hooks dispatched by the host application.
     */
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'epg.cache.generated') {
            return PluginActionResult::success("Hook [{$hook}] ignored - not relevant.");
        }

        $autoRun = $context->settings['auto_run_on_cache'] ?? true;
        if (! $autoRun) {
            return PluginActionResult::success('Auto-run disabled - skipping.');
        }

        $epgId = $payload['epg_id'] ?? null;
        $userId = $payload['user_id'] ?? null;

        if (! $epgId || ! $userId) {
            return PluginActionResult::failure('Missing epg_id or user_id in hook payload.');
        }

        $context->info("EPG cache generated (ID: {$epgId}). Running enrichment.");

        return $this->doEnrich($epgId, $context);
    }

    /**
     * Manual enrich action from UI.
     */
    private function enrichEpg(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $epgId = $payload['epg_id'] ?? null;

        if (! $epgId) {
            return PluginActionResult::failure('EPG source is required.');
        }

        $epg = Epg::find($epgId);
        if (! $epg) {
            return PluginActionResult::failure("EPG [{$epgId}] not found.");
        }

        $context->info("Starting manual EPG enrichment for '{$epg->name}'.");

        return $this->doEnrich($epgId, $context);
    }

    /**
     * Core enrichment logic. Reads cached JSONL, enriches with TMDB, fills gaps, writes back.
     */
    private function doEnrich(int $epgId, PluginExecutionContext $context): PluginActionResult
    {
        $epg = Epg::find($epgId);
        if (! $epg) {
            return PluginActionResult::failure("EPG [{$epgId}] not found.");
        }

        $cacheService = app(EpgCacheService::class);
        if (! $cacheService->isCacheValid($epg)) {
            return PluginActionResult::failure("EPG cache for '{$epg->name}' is not valid. Sync the EPG first.");
        }

        $settings = $context->settings;
        $enrichTmdb = $settings['enrich_from_tmdb'] ?? true;
        $fillGaps = $settings['fill_gaps'] ?? false;
        $minGapMinutes = max(1, (int) ($settings['min_gap_minutes'] ?? 30));
        $gapTitle = $settings['gap_placeholder_title'] ?? 'No programme data';
        $overwrite = $settings['overwrite_existing'] ?? false;
        $enrichCategories = $settings['enrich_categories'] ?? true;
        $enrichDescriptions = $settings['enrich_descriptions'] ?? true;

        // Load TMDB service if enrichment enabled
        $tmdb = null;
        if ($enrichTmdb) {
            $tmdb = app(TmdbService::class);
            if (! $tmdb->isConfigured()) {
                $context->warning('TMDB API key not configured. Skipping TMDB enrichment.');
                $enrichTmdb = false;
                $tmdb = null;
            }
        }

        if (! $enrichTmdb && ! $fillGaps) {
            return PluginActionResult::success('Both TMDB enrichment and gap filling are disabled - nothing to do.');
        }

        // Load TMDB lookup cache from disk
        $tmdbCache = $this->loadTmdbCache();

        // Read metadata to find date range
        $metadata = $this->readMetadata($epg);
        if (! $metadata) {
            return PluginActionResult::failure('Could not read EPG cache metadata.');
        }

        $minDate = $metadata['programme_date_range']['min_date'] ?? null;
        $maxDate = $metadata['programme_date_range']['max_date'] ?? null;
        if (! $minDate || ! $maxDate) {
            return PluginActionResult::failure('EPG cache has no programme date range.');
        }

        $cacheDir = "epg-cache/{$epg->uuid}/v1";
        $currentDate = Carbon::parse($minDate);
        $endDate = Carbon::parse($maxDate);
        $totalDays = $currentDate->diffInDays($endDate) + 1;
        $dayIndex = 0;

        $stats = [
            'programmes_enriched' => 0,
            'posters_added' => 0,
            'categories_added' => 0,
            'descriptions_added' => 0,
            'gaps_filled' => 0,
            'days_processed' => 0,
            'tmdb_lookups' => 0,
            'tmdb_cache_hits' => 0,
        ];

        while ($currentDate->lte($endDate)) {
            $dayIndex++;
            $dateStr = $currentDate->format('Y-m-d');
            $jsonlFile = "{$cacheDir}/programmes-{$dateStr}.jsonl";

            $context->heartbeat(
                "Processing {$dateStr} ({$dayIndex}/{$totalDays})...",
                progress: (int) (($dayIndex / $totalDays) * 100)
            );

            if ($context->cancellationRequested()) {
                $this->saveTmdbCache($tmdbCache);

                return PluginActionResult::cancelled('Enrichment cancelled.', $stats);
            }

            if (Storage::disk('local')->exists($jsonlFile)) {
                $result = $this->processDateFile(
                    $jsonlFile,
                    $tmdb,
                    $tmdbCache,
                    $enrichTmdb,
                    $fillGaps,
                    $minGapMinutes,
                    $gapTitle,
                    $overwrite,
                    $enrichCategories,
                    $enrichDescriptions,
                    $context,
                );

                $stats['programmes_enriched'] += $result['enriched'];
                $stats['posters_added'] += $result['posters'];
                $stats['categories_added'] += $result['categories'];
                $stats['descriptions_added'] += $result['descriptions'];
                $stats['gaps_filled'] += $result['gaps'];
                $stats['tmdb_lookups'] += $result['lookups'];
                $stats['tmdb_cache_hits'] += $result['cache_hits'];
            }

            $stats['days_processed']++;
            $currentDate->addDay();
        }

        // Persist TMDB lookup cache
        $this->saveTmdbCache($tmdbCache);

        $summary = "Enrichment complete for '{$epg->name}': "
            ."{$stats['programmes_enriched']} programmes enriched, "
            ."{$stats['gaps_filled']} gaps filled.";

        $context->info($summary, $stats);

        return PluginActionResult::success($summary, $stats);
    }

    /**
     * Process a single date's JSONL file: enrich programmes and fill gaps.
     *
     * @return array{enriched: int, posters: int, categories: int, descriptions: int, gaps: int, lookups: int, cache_hits: int}
     */
    private function processDateFile(
        string $jsonlFile,
        ?TmdbService $tmdb,
        array &$tmdbCache,
        bool $enrichTmdb,
        bool $fillGaps,
        int $minGapMinutes,
        string $gapTitle,
        bool $overwrite,
        bool $enrichCategories,
        bool $enrichDescriptions,
        PluginExecutionContext $context,
    ): array {
        $result = [
            'enriched' => 0,
            'posters' => 0,
            'categories' => 0,
            'descriptions' => 0,
            'gaps' => 0,
            'lookups' => 0,
            'cache_hits' => 0,
        ];

        $fullPath = Storage::disk('local')->path($jsonlFile);

        // Read all records grouped by channel
        $channelProgrammes = [];
        if (($handle = fopen($fullPath, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! $record || ! isset($record['channel'], $record['programme'])) {
                    continue;
                }

                $channelProgrammes[$record['channel']][] = $record['programme'];
            }
            fclose($handle);
        }

        if (empty($channelProgrammes)) {
            return $result;
        }

        $enrichedRecords = [];

        foreach ($channelProgrammes as $channelId => $programmes) {
            // Sort by start time
            usort($programmes, fn ($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));

            // Enrich each programme with TMDB data
            if ($enrichTmdb && $tmdb) {
                foreach ($programmes as &$programme) {
                    $enrichResult = $this->enrichProgrammeFromTmdb(
                        $programme,
                        $tmdb,
                        $tmdbCache,
                        $overwrite,
                        $enrichCategories,
                        $enrichDescriptions,
                    );

                    $result['enriched'] += $enrichResult['changed'] ? 1 : 0;
                    $result['posters'] += $enrichResult['poster'] ? 1 : 0;
                    $result['categories'] += $enrichResult['category'] ? 1 : 0;
                    $result['descriptions'] += $enrichResult['description'] ? 1 : 0;
                    $result['lookups'] += $enrichResult['lookup'] ? 1 : 0;
                    $result['cache_hits'] += $enrichResult['cache_hit'] ? 1 : 0;

                    if ($context->cancellationRequested()) {
                        break 2;
                    }
                }
                unset($programme);
            }

            // Fill gaps between programmes
            if ($fillGaps) {
                $programmes = $this->fillGapsForChannel($programmes, $minGapMinutes, $gapTitle, $result);
            }

            foreach ($programmes as $programme) {
                $enrichedRecords[] = json_encode([
                    'channel' => $channelId,
                    'programme' => $programme,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // Write the enriched file back (atomic: write to temp, then rename)
        $tempPath = $fullPath.'.enriching';
        if (($handle = fopen($tempPath, 'w')) !== false) {
            foreach ($enrichedRecords as $line) {
                fwrite($handle, $line."\n");
            }
            fclose($handle);
            rename($tempPath, $fullPath);
        }

        return $result;
    }

    /**
     * Enrich a single programme with TMDB data.
     *
     * @return array{changed: bool, poster: bool, category: bool, description: bool, lookup: bool, cache_hit: bool}
     */
    private function enrichProgrammeFromTmdb(
        array &$programme,
        TmdbService $tmdb,
        array &$cache,
        bool $overwrite,
        bool $enrichCategories,
        bool $enrichDescriptions,
    ): array {
        $result = [
            'changed' => false,
            'poster' => false,
            'category' => false,
            'description' => false,
            'lookup' => false,
            'cache_hit' => false,
        ];

        $title = $programme['title'] ?? '';
        if ($title === '') {
            return $result;
        }

        // Check if programme already has all data we'd enrich
        $hasIcon = ! empty($programme['icon']);
        $hasCategory = ! empty($programme['category']);
        $hasDesc = ! empty($programme['desc']);

        if (! $overwrite && $hasIcon && ($hasCategory || ! $enrichCategories) && ($hasDesc || ! $enrichDescriptions)) {
            return $result;
        }

        // Normalize title for cache key
        $cacheKey = $this->normalizeCacheKey($title);

        // Check TMDB lookup cache
        if (isset($cache[$cacheKey])) {
            $result['cache_hit'] = true;
            $tmdbData = $cache[$cacheKey];
        } else {
            // Try movie search first, then TV
            $result['lookup'] = true;

            $tmdbData = $tmdb->searchMovie($title, tryFallback: true);
            if ($tmdbData && ($tmdbData['tmdb_id'] ?? null)) {
                $details = $tmdb->getMovieDetails($tmdbData['tmdb_id']);
                if ($details) {
                    $tmdbData = array_merge($tmdbData, $details);
                }
            } else {
                $tmdbData = $tmdb->searchTvSeries($title);
                if ($tmdbData && ($tmdbData['tmdb_id'] ?? null)) {
                    $details = $tmdb->getTvSeriesDetails($tmdbData['tmdb_id']);
                    if ($details) {
                        $tmdbData = array_merge($tmdbData, $details);
                    }
                }
            }

            // Cache the result (even if null - avoids re-lookups)
            $cache[$cacheKey] = $tmdbData;
        }

        if (! $tmdbData) {
            return $result;
        }

        // Enrich poster/icon
        $posterUrl = $tmdbData['poster_url'] ?? null;
        $backdropUrl = $tmdbData['backdrop_url'] ?? null;

        if ($posterUrl && ($overwrite || ! $hasIcon)) {
            $programme['icon'] = $posterUrl;
            $result['poster'] = true;
            $result['changed'] = true;
        }

        // Add backdrop to images array
        if ($backdropUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($backdropUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $backdropUrl,
                    'type' => 'backdrop',
                    'width' => 1920,
                    'height' => 1080,
                    'orient' => 'L',
                    'size' => 3,
                ];
                $result['changed'] = true;
            }
        }

        // Add poster to images array if not already the icon
        if ($posterUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($posterUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $posterUrl,
                    'type' => 'poster',
                    'width' => 500,
                    'height' => 750,
                    'orient' => 'P',
                    'size' => 2,
                ];
                $result['changed'] = true;
            }
        }

        // Enrich category/genre
        $genres = $tmdbData['genres'] ?? '';
        if ($enrichCategories && $genres !== '' && ($overwrite || ! $hasCategory)) {
            // Take the first genre if comma-separated
            $firstGenre = trim(explode(',', $genres)[0]);
            if ($firstGenre !== '') {
                $programme['category'] = $firstGenre;
                $result['category'] = true;
                $result['changed'] = true;
            }
        }

        // Enrich description
        $overview = $tmdbData['overview'] ?? '';
        if ($enrichDescriptions && $overview !== '' && ($overwrite || ! $hasDesc)) {
            $programme['desc'] = $overview;
            $result['description'] = true;
            $result['changed'] = true;
        }

        return $result;
    }

    /**
     * Fill gaps between sorted programmes for one channel.
     *
     * @param  array<int, array>  $programmes  Sorted by start time
     * @param  array  $result  Stats counter (passed by reference)
     * @return array<int, array> Programmes with gaps inserted
     */
    private function fillGapsForChannel(array $programmes, int $minGapMinutes, string $gapTitle, array &$result): array
    {
        if (count($programmes) < 2) {
            return $programmes;
        }

        $filled = [];

        for ($i = 0; $i < count($programmes); $i++) {
            $filled[] = $programmes[$i];

            // Check gap to next programme
            if ($i < count($programmes) - 1) {
                $currentStop = $programmes[$i]['stop'] ?? null;
                $nextStart = $programmes[$i + 1]['start'] ?? null;

                if (! $currentStop || ! $nextStart) {
                    continue;
                }

                try {
                    $stopTime = Carbon::parse($currentStop);
                    $startTime = Carbon::parse($nextStart);
                    $gapMinutes = $stopTime->diffInMinutes($startTime, false);

                    if ($gapMinutes >= $minGapMinutes) {
                        $filled[] = [
                            'channel' => $programmes[$i]['channel'] ?? '',
                            'start' => $stopTime->toISOString(),
                            'stop' => $startTime->toISOString(),
                            'title' => $gapTitle,
                            'subtitle' => '',
                            'desc' => '',
                            'category' => '',
                            'episode_num' => '',
                            'rating' => '',
                            'icon' => '',
                            'images' => [],
                            'new' => false,
                        ];
                        $result['gaps']++;
                    }
                } catch (\Exception $e) {
                    // Skip malformed timestamps
                    continue;
                }
            }
        }

        return $filled;
    }

    /**
     * Report plugin health and cache statistics.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $tmdb = app(TmdbService::class);
        $tmdbConfigured = $tmdb->isConfigured();

        $cacheFile = 'plugin-data/epg-enricher/tmdb-cache.json';
        $cacheEntries = 0;
        if (Storage::disk('local')->exists($cacheFile)) {
            $data = json_decode(Storage::disk('local')->get($cacheFile), true);
            $cacheEntries = is_array($data) ? count($data) : 0;
        }

        return PluginActionResult::success('EPG Enricher plugin is healthy.', [
            'plugin_id' => 'epg-enricher',
            'tmdb_configured' => $tmdbConfigured,
            'tmdb_cache_entries' => $cacheEntries,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Read EPG cache metadata.
     */
    private function readMetadata(Epg $epg): ?array
    {
        $path = "epg-cache/{$epg->uuid}/v1/metadata.json";
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($path), true);
    }

    /**
     * Load TMDB title lookup cache from disk.
     *
     * @return array<string, array|null>
     */
    private function loadTmdbCache(): array
    {
        $path = 'plugin-data/epg-enricher/tmdb-cache.json';
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $data = json_decode(Storage::disk('local')->get($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Save TMDB title lookup cache to disk.
     *
     * @param  array<string, array|null>  $cache
     */
    private function saveTmdbCache(array $cache): void
    {
        Storage::disk('local')->makeDirectory('plugin-data/epg-enricher');
        Storage::disk('local')->put(
            'plugin-data/epg-enricher/tmdb-cache.json',
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Normalize a programme title into a stable cache key.
     */
    private function normalizeCacheKey(string $title): string
    {
        $key = mb_strtolower(trim($title));
        // Strip common suffixes like "(2024)", year patterns
        $key = preg_replace('/\s*\(\d{4}\)\s*$/', '', $key);
        // Collapse whitespace
        $key = preg_replace('/\s+/', ' ', $key);

        return $key;
    }
}
