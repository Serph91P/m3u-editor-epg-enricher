<?php

namespace AppLocalPlugins\EpgEnricher;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheService;
use App\Services\TmdbService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;

class Plugin implements EpgProcessorPluginInterface, HookablePluginInterface
{
    /**
     * Mapping of TMDB genre names (English + German) to Emby EPG categories.
     *
     * Emby supports 4 color-coded categories in the guide:
     *   Movie, News, Kids, Sports
     * Other categories (Series, Documentary, Music, Education) are valid
     * but receive no color highlighting.
     *
     * For ambiguous genres (e.g. "Action" can be a movie or TV series),
     * the TMDB media type overrides the mapping in {@see mapToEmbyCategory()}.
     *
     * Covers every official TMDB genre for both Movies and TV shows.
     *
     * @var array<string, string>
     */
    private const array EMBY_GENRE_MAP = [
        // ── News (color-coded) ──────────────────────────────────────
        'news' => 'News',
        'nachrichten' => 'News',
        'journalism' => 'News',
        'journalismus' => 'News',
        'current affairs' => 'News',

        // ── Sports (color-coded) ────────────────────────────────────
        'sport' => 'Sports',
        'sports' => 'Sports',
        'basketball' => 'Sports',
        'baseball' => 'Sports',
        'football' => 'Sports',

        // ── Kids / Children (color-coded) ───────────────────────────
        'kids' => 'Kids',
        'kinder' => 'Kids',
        'children' => 'Kids',
        'childrens' => 'Kids',
        'disney' => 'Kids',

        // ── Movie (color-coded) ─────────────────────────────────────
        // TMDB Movie-only genres
        'action' => 'Movie',
        'adventure' => 'Movie',
        'abenteuer' => 'Movie',
        'horror' => 'Movie',
        'thriller' => 'Movie',
        'science fiction' => 'Movie',
        'fantasy' => 'Movie',
        'romance' => 'Movie',
        'liebesfilm' => 'Movie',
        'war' => 'Movie',
        'kriegsfilm' => 'Movie',
        'tv movie' => 'Movie',
        'tv-film' => 'Movie',

        // Shared genres — mapped to Movie, overridden to Series by media type
        'comedy' => 'Movie',
        'komödie' => 'Movie',
        'crime' => 'Movie',
        'krimi' => 'Movie',
        'mystery' => 'Movie',
        'drama' => 'Movie',
        'western' => 'Movie',
        'animation' => 'Movie',
        'family' => 'Movie',
        'familie' => 'Movie',

        // ── Series (no color — TV-exclusive TMDB genres) ────────────
        'action & adventure' => 'Series',
        'action & abenteuer' => 'Series',
        'sci-fi & fantasy' => 'Series',
        'war & politics' => 'Series',
        'krieg & politik' => 'Series',
        'soap' => 'Series',
        'reality' => 'Series',
        'talk' => 'Series',

        // ── Documentary (no color) ──────────────────────────────────
        'documentary' => 'Documentary',
        'dokumentarfilm' => 'Documentary',
        'dokumentation' => 'Documentary',
        'history' => 'Documentary',
        'historie' => 'Documentary',

        // ── Music (no color) ────────────────────────────────────────
        'music' => 'Music',
        'musik' => 'Music',

        // ── Education (no color) ────────────────────────────────────
        'education' => 'Education',
        'bildung' => 'Education',
    ];

    /**
     * Keyword patterns for title-based category detection.
     *
     * Used as a fallback when TMDB cannot find a match (live sports, news
     * broadcasts, kids channels, etc.). Keywords are matched with word
     * boundaries to prevent false positives.
     *
     * @var array<string, list<string>>
     */
    private const array TITLE_KEYWORD_CATEGORIES = [
        'Sports' => [
            // Motorsport
            'formel 1', 'formula 1', 'f1', 'motogp', 'nascar', 'dtm',
            'formel e', 'formula e', 'indycar', 'rallye', 'rally',
            // Football / Soccer
            'bundesliga', 'champions league', 'europa league', 'conference league',
            'premier league', 'la liga', 'serie a', 'ligue 1', 'eredivisie',
            'dfb-pokal', 'dfb pokal', 'copa america', 'euro 2024', 'em 2024',
            'world cup', 'weltmeisterschaft', 'copa del rey',
            'fifa', 'uefa', 'fußball', 'fussball', 'soccer',
            // US Sports
            'nfl', 'nba', 'nhl', 'mlb', 'mls', 'super bowl',
            // Tennis
            'wimbledon', 'roland garros', 'us open tennis', 'australian open',
            'atp', 'wta',
            // Winter Sports
            'biathlon', 'ski alpin', 'skispringen', 'ski jumping',
            'langlauf', 'cross-country skiing', 'bob', 'rodeln', 'luge',
            // Cycling
            'tour de france', 'giro d\'italia', 'vuelta',
            // Boxing / MMA
            'boxen', 'boxing', 'ufc', 'mma',
            // Other Sports
            'olympia', 'olympics', 'olympische spiele',
            'leichtathletik', 'athletics', 'handball', 'volleyball',
            'eishockey', 'ice hockey', 'golf', 'rugby', 'cricket',
            'darts', 'snooker', 'sportschau', 'sport1',
            'ringen', 'wrestling', 'schwimmen', 'swimming',
        ],
        'News' => [
            'tagesschau', 'tagesthemen', 'heute', 'heute journal',
            'rtl aktuell', 'sat.1 nachrichten', 'newstime',
            'cnn', 'bbc news', 'sky news', 'euronews', 'al jazeera',
            'ntv', 'n-tv', 'welt news', 'ard extra', 'zdf spezial',
            'nachrichten', 'news', 'breaking news',
            'morgenmagazin', 'moma', 'frühstücksfernsehen',
            'presseclub', 'anne will', 'hart aber fair',
            'markus lanz', 'maischberger', 'sandra maischberger',
            'wetter', 'weather',
        ],
        'Kids' => [
            'kika', 'kinder', 'kids', 'junior',
            'nick', 'nickelodeon', 'nicktoons',
            'cartoon network', 'disney channel', 'disney junior',
            'super rtl', 'toggo',
            'sesamstraße', 'sesame street',
            'peppa pig', 'peppa wutz', 'paw patrol',
            'spongebob', 'bluey', 'bob der baumeister',
            'bob the builder', 'feuerwehrmann sam', 'fireman sam',
            'bibi blocksberg', 'bibi und tina', 'benjamin blümchen',
            'die sendung mit der maus', 'löwenzahn', 'wickie',
        ],
        'Documentary' => [
            'terra x', 'planet erde', 'planet earth',
            'national geographic', 'nat geo', 'discovery',
            'arte doku', 'zdf doku', 'ard doku',
            'dokumentation', 'documentary', 'doku',
            'history channel', 'history',
            'galileo', 'abenteuer leben', 'wissen',
            'quarks', 'nano', 'scobel', 'leschs kosmos',
            'woher wissen wir das', '37 grad', 'reportage',
        ],
    ];

    /**
     * Handle manual actions triggered from the plugin UI.
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'enrich_epg' => $this->enrichEpg($payload, $context),
            'health_check' => $this->healthCheck($context),
            'clear_state' => $this->clearEnrichmentState($context),
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
        $playlistIds = $payload['playlist_ids'] ?? [];

        if (! $epgId || ! $userId) {
            return PluginActionResult::failure('Missing epg_id or user_id in hook payload.');
        }

        // Filter playlists if the user restricted auto-run to specific ones
        $allowedPlaylistIds = $context->settings['auto_run_playlists'] ?? [];
        if (! empty($allowedPlaylistIds)) {
            $playlistIds = array_values(array_intersect($playlistIds, $allowedPlaylistIds));
            if (empty($playlistIds)) {
                return PluginActionResult::success('No matching playlists for auto-run - skipping.');
            }
        }

        $context->heartbeat("EPG cache generated (ID: {$epgId}). Running enrichment for playlist channels.");

        return $this->doEnrich($epgId, $playlistIds, $context);
    }

    /**
     * Manual enrich action from UI.
     */
    private function enrichEpg(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = $payload['playlist_id'] ?? null;

        if (! $playlistId) {
            return PluginActionResult::failure('Playlist is required.');
        }

        $playlist = Playlist::find($playlistId);
        if (! $playlist) {
            return PluginActionResult::failure("Playlist [{$playlistId}] not found.");
        }

        // Resolve distinct EPG IDs from the playlist's enabled channels
        $epgIds = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->whereHas('epgChannel')
            ->join('epg_channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->distinct()
            ->pluck('epg_channels.epg_id')
            ->all();

        if (empty($epgIds)) {
            return PluginActionResult::success("No active channels with EPG mappings in '{$playlist->name}' - nothing to enrich.");
        }

        $playlistIds = [$playlist->id];
        $totalChannels = $this->countTargetChannels($epgIds, $playlistIds);
        $context->heartbeat("Starting EPG enrichment for playlist '{$playlist->name}' ({$totalChannels} active channels across ".count($epgIds).' EPG source(s)).');

        // Enrich each EPG source referenced by this playlist
        $combinedStats = [];
        $lastResult = null;

        foreach ($epgIds as $epgId) {
            $lastResult = $this->doEnrich($epgId, $playlistIds, $context);

            if (! $lastResult->success) {
                return $lastResult;
            }

            // If doEnrich returned early (e.g. TMDB disabled), propagate directly
            if (empty($lastResult->data)) {
                return $lastResult;
            }

            foreach ($lastResult->data as $key => $value) {
                if (is_int($value)) {
                    $combinedStats[$key] = ($combinedStats[$key] ?? 0) + $value;
                } else {
                    $combinedStats[$key] = $value;
                }
            }
        }

        $summary = "Enrichment complete for playlist '{$playlist->name}': "
            .($combinedStats['programmes_updated'] ?? 0).'/'
            .($combinedStats['programmes_processed'] ?? 0).' programmes updated '
            ."across {$totalChannels} active channels.";

        return PluginActionResult::success($summary, $combinedStats);
    }

    /**
     * Core enrichment logic. Reads cached JSONL, enriches with TMDB, writes back.
     * Only processes channels that are mapped in the given playlists.
     *
     * @param  array<int>  $playlistIds  Playlist IDs to scope enrichment to
     */
    private function doEnrich(int $epgId, array $playlistIds, PluginExecutionContext $context): PluginActionResult
    {
        $epg = Epg::find($epgId);
        if (! $epg) {
            return PluginActionResult::failure("EPG [{$epgId}] not found.");
        }

        $cacheService = app(EpgCacheService::class);
        if (! $cacheService->isCacheValid($epg)) {
            return PluginActionResult::failure("EPG cache for '{$epg->name}' is not valid. Sync the EPG first.");
        }

        // Resolve which EPG channel IDs (strings) are actually used in playlists
        $targetChannelIds = $this->resolveTargetChannelIds($epgId, $playlistIds);

        if (empty($targetChannelIds)) {
            return PluginActionResult::success('No playlist channels are mapped to this EPG - nothing to enrich.');
        }

        $settings = $context->settings;
        $enrichTmdb = $settings['enrich_from_tmdb'] ?? true;
        $overwrite = $settings['overwrite_existing'] ?? false;
        $enrichCategories = $settings['enrich_categories'] ?? true;
        $enrichDescriptions = $settings['enrich_descriptions'] ?? true;
        $enrichPosters = $settings['enrich_posters'] ?? true;
        $enrichBackdrops = $settings['enrich_backdrops'] ?? true;
        $mapEmbyGenres = $settings['map_emby_genres'] ?? false;
        $keywordDetection = $settings['keyword_category_detection'] ?? true;
        $enrichSportsEvents = $settings['enrich_sports_events'] ?? false;
        $sportsDbApiKey = $settings['sportsdb_api_key'] ?? '';
        $sportsDbCountry = $settings['sportsdb_country'] ?? '';

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

        // Override TMDB search language if configured in plugin settings
        $tmdbLanguage = trim($settings['tmdb_language'] ?? '');
        if ($tmdb && $tmdbLanguage !== '') {
            $this->setTmdbLanguage($tmdb, $tmdbLanguage);
            $context->heartbeat("Using TMDB search language: {$tmdbLanguage}");
        }

        if (! $enrichTmdb) {
            return PluginActionResult::success('TMDB enrichment is disabled - nothing to do.');
        }

        // Load TMDB lookup cache from disk
        $tmdbCache = $this->loadTmdbCache();

        // If language changed, the TMDB lookup cache contains results in the old
        // language. Clear it so titles are re-searched with the new language.
        $storedLanguage = $tmdbCache['__language'] ?? null;
        $currentLanguage = $tmdbLanguage !== '' ? $tmdbLanguage : '__global';
        if ($storedLanguage !== null && $storedLanguage !== $currentLanguage) {
            $context->heartbeat('TMDB language changed - clearing lookup cache for fresh results.');
            $tmdbCache = [];
        }
        $tmdbCache['__language'] = $currentLanguage;

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

        // Load enrichment state and check for invalidation
        $enrichmentState = $this->loadEnrichmentState();
        $stateKey = "epg_{$epgId}";
        $settingsHash = $this->computeSettingsHash($settings);
        $channelsHash = $this->computeChannelsHash($targetChannelIds);

        $epgState = $enrichmentState[$stateKey] ?? [];
        $storedSettingsHash = $epgState['settings_hash'] ?? null;
        $storedChannelsHash = $epgState['channels_hash'] ?? null;
        $fileStates = $epgState['files'] ?? [];

        // Invalidate all file states if settings or channels changed
        if ($storedSettingsHash !== null && $storedSettingsHash !== $settingsHash) {
            $context->heartbeat('Settings changed since last enrichment - re-processing all files.');
            $fileStates = [];
        } elseif ($storedChannelsHash !== null && $storedChannelsHash !== $channelsHash) {
            $context->heartbeat('Channel mappings changed since last enrichment - re-processing all files.');
            $fileStates = [];
        }

        $cacheDir = "epg-cache/{$epg->uuid}/v1";
        $currentDate = Carbon::parse($minDate);
        $endDate = Carbon::parse($maxDate);
        $totalDays = $currentDate->diffInDays($endDate) + 1;
        $dayIndex = 0;

        $stats = [
            'programmes_processed' => 0,
            'programmes_updated' => 0,
            'programmes_already_enriched' => 0,
            'posters_added' => 0,
            'categories_added' => 0,
            'descriptions_added' => 0,
            'days_processed' => 0,
            'days_skipped' => 0,
            'days_unchanged' => 0,
            'channels_targeted' => count($targetChannelIds),
            'tmdb_lookups' => 0,
            'tmdb_cache_hits' => 0,
            'sportsdb_matches' => 0,
            'sportsdb_posters' => 0,
        ];

        $sportsDbCache = [];

        $newFileStates = [];

        while ($currentDate->lte($endDate)) {
            $dayIndex++;
            $dateStr = $currentDate->format('Y-m-d');
            $jsonlFile = "{$cacheDir}/programmes-{$dateStr}.jsonl";
            $fileName = "programmes-{$dateStr}.jsonl";

            if ($context->cancellationRequested()) {
                $this->saveTmdbCache($tmdbCache);
                // Merge new file states with existing ones before saving
                $enrichmentState[$stateKey] = [
                    'settings_hash' => $settingsHash,
                    'channels_hash' => $channelsHash,
                    'files' => array_merge($fileStates, $newFileStates),
                ];
                $this->saveEnrichmentState($enrichmentState);

                return PluginActionResult::cancelled('Enrichment cancelled.', $stats);
            }

            if (! Storage::disk('local')->exists($jsonlFile)) {
                $context->heartbeat(
                    "Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - file missing",
                    progress: (int) (($dayIndex / $totalDays) * 100)
                );
                $stats['days_processed']++;
                $currentDate->addDay();

                continue;
            }

            // Compute source content hash to check if file data changed
            $fullPath = Storage::disk('local')->path($jsonlFile);
            $currentHash = md5_file($fullPath);
            $storedSourceHash = $fileStates[$fileName]['source_hash'] ?? null;
            $storedEnrichedHash = $fileStates[$fileName]['enriched_hash'] ?? null;

            // Skip if current file matches either the original source or the enriched version
            if ($storedSourceHash !== null && ($currentHash === $storedSourceHash || $currentHash === $storedEnrichedHash)) {
                // Source data unchanged since last enrichment - skip
                $context->heartbeat(
                    "Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - unchanged source data",
                    progress: (int) (($dayIndex / $totalDays) * 100)
                );
                $newFileStates[$fileName] = $fileStates[$fileName];
                $stats['days_skipped']++;
                $stats['days_processed']++;
                $currentDate->addDay();

                continue;
            }

            $context->heartbeat(
                "Processing {$dateStr} ({$dayIndex}/{$totalDays})...",
                progress: (int) (($dayIndex / $totalDays) * 100)
            );

            $result = $this->processDateFile(
                $jsonlFile,
                $targetChannelIds,
                $tmdb,
                $tmdbCache,
                $overwrite,
                $enrichCategories,
                $enrichDescriptions,
                $enrichPosters,
                $enrichBackdrops,
                $mapEmbyGenres,
                $keywordDetection,
                $enrichSportsEvents,
                $sportsDbApiKey,
                $sportsDbCountry,
                $sportsDbCache,
                $context,
            );

            $stats['programmes_processed'] += $result['processed'];
            $stats['programmes_updated'] += $result['updated'];
            $stats['programmes_already_enriched'] += $result['already_enriched'];
            $stats['posters_added'] += $result['posters'];
            $stats['categories_added'] += $result['categories'];
            $stats['descriptions_added'] += $result['descriptions'];
            $stats['tmdb_lookups'] += $result['lookups'];
            $stats['tmdb_cache_hits'] += $result['cache_hits'];
            $stats['sportsdb_matches'] += $result['sportsdb_matches'];
            $stats['sportsdb_posters'] += $result['sportsdb_posters'];

            if (! $result['modified']) {
                $stats['days_unchanged']++;
            }

            // Store the source hash of the un-enriched file. On the next run after
            // an EPG sync, the freshly generated file will be compared against this hash.
            // If the EPG source data for this day hasn't changed, the hash will match
            // and the file will be skipped.
            // If the file was enriched (modified), also store the enriched file's hash
            // so that manual re-runs without an EPG sync in between are also skipped.
            $enrichedHash = $result['modified']
                ? md5_file(Storage::disk('local')->path($jsonlFile))
                : $currentHash;

            $newFileStates[$fileName] = [
                'source_hash' => $currentHash,
                'enriched_hash' => $enrichedHash,
                'enriched_at' => now()->toIso8601String(),
                'programmes_updated' => $result['updated'],
            ];

            $stats['days_processed']++;
            $currentDate->addDay();
        }

        // Persist TMDB lookup cache
        $this->saveTmdbCache($tmdbCache);

        // Persist enrichment state
        $enrichmentState[$stateKey] = [
            'settings_hash' => $settingsHash,
            'channels_hash' => $channelsHash,
            'files' => $newFileStates,
        ];
        $this->saveEnrichmentState($enrichmentState);

        $skippedInfo = $stats['days_skipped'] > 0
            ? " ({$stats['days_skipped']} day(s) skipped - unchanged source data)"
            : '';

        $summary = "Enrichment complete for '{$epg->name}': "
            ."{$stats['programmes_updated']}/{$stats['programmes_processed']} programmes updated "
            ."across {$stats['channels_targeted']} channels, {$stats['days_processed']} day(s){$skippedInfo}.";

        return PluginActionResult::success($summary, $stats);
    }

    /**
     * Resolve EPG channel_id strings that are mapped in the given playlists.
     *
     * @param  array<int>  $playlistIds
     * @return array<string> EPG channel_id strings used in JSONL files
     */
    private function resolveTargetChannelIds(int $epgId, array $playlistIds): array
    {
        if (empty($playlistIds)) {
            return [];
        }

        // Get EpgChannel IDs referenced by enabled channels in these playlists
        $epgChannelDbIds = Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->distinct()
            ->pluck('epg_channel_id');

        // Map to the string channel_id used in JSONL, filtered to this EPG
        return EpgChannel::query()
            ->where('epg_id', $epgId)
            ->whereIn('id', $epgChannelDbIds)
            ->pluck('channel_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Count how many enabled playlist channels target the given EPG(s).
     *
     * @param  array<int>|int  $epgIds
     * @param  array<int>  $playlistIds
     */
    private function countTargetChannels(array|int $epgIds, array $playlistIds): int
    {
        if (empty($playlistIds)) {
            return 0;
        }

        $epgIds = (array) $epgIds;

        return Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->whereHas('epgChannel', fn ($q) => $q->whereIn('epg_id', $epgIds))
            ->count();
    }

    /**
     * Process a single date's JSONL file: enrich only targeted playlist channels.
     *
     * @param  array<string>  $targetChannelIds  EPG channel_id strings to enrich
     * @return array{enriched: int, skipped: int, posters: int, categories: int, descriptions: int, lookups: int, cache_hits: int, modified: bool}
     */
    private function processDateFile(
        string $jsonlFile,
        array $targetChannelIds,
        TmdbService $tmdb,
        array &$tmdbCache,
        bool $overwrite,
        bool $enrichCategories,
        bool $enrichDescriptions,
        bool $enrichPosters,
        bool $enrichBackdrops,
        bool $mapEmbyGenres,
        bool $keywordDetection,
        bool $enrichSportsEvents,
        string $sportsDbApiKey,
        string $sportsDbCountry,
        array &$sportsDbCache,
        PluginExecutionContext $context,
    ): array {
        $result = [
            'processed' => 0,
            'updated' => 0,
            'already_enriched' => 0,
            'posters' => 0,
            'categories' => 0,
            'descriptions' => 0,
            'lookups' => 0,
            'cache_hits' => 0,
            'sportsdb_matches' => 0,
            'sportsdb_posters' => 0,
            'modified' => false,
        ];

        // Pre-fetch TheSportsDB events for this date if sports enrichment is enabled
        $sportsEvents = [];
        if ($enrichSportsEvents) {
            // Extract date from filename (programmes-YYYY-MM-DD.jsonl)
            if (preg_match('/programmes-(\d{4}-\d{2}-\d{2})\.jsonl$/', $jsonlFile, $m)) {
                $sportsEvents = $this->fetchSportsDbEventsForDate($m[1], $sportsDbApiKey, $sportsDbCountry, $sportsDbCache);
            }
        }

        $fullPath = Storage::disk('local')->path($jsonlFile);
        $targetSet = array_flip($targetChannelIds);

        // Read all records, enrich only targeted channels
        $enrichedLines = [];
        if (($handle = fopen($fullPath, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! $record || ! isset($record['channel'], $record['programme'])) {
                    $enrichedLines[] = $line;

                    continue;
                }

                // Only enrich channels that are mapped in playlists
                if (! isset($targetSet[$record['channel']])) {
                    $enrichedLines[] = $line;

                    continue;
                }

                $result['processed']++;

                $programme = $record['programme'];

                $enrichResult = $this->enrichProgrammeFromTmdb(
                    $programme,
                    $tmdb,
                    $tmdbCache,
                    $overwrite,
                    $enrichCategories,
                    $enrichDescriptions,
                    $enrichPosters,
                    $enrichBackdrops,
                    $mapEmbyGenres,
                    $keywordDetection,
                );

                if ($enrichResult['changed']) {
                    $result['modified'] = true;
                    $result['updated']++;
                } else {
                    $result['already_enriched']++;
                }

                $result['posters'] += $enrichResult['poster'] ? 1 : 0;
                $result['categories'] += $enrichResult['category'] ? 1 : 0;
                $result['descriptions'] += $enrichResult['description'] ? 1 : 0;
                $result['lookups'] += $enrichResult['lookup'] ? 1 : 0;
                $result['cache_hits'] += $enrichResult['cache_hit'] ? 1 : 0;

                // TheSportsDB enrichment for sport programmes
                if (! empty($sportsEvents) && ($programme['category'] ?? '') === 'Sports') {
                    $matchedEvent = $this->matchSportsEvent($programme, $sportsEvents);
                    if ($matchedEvent) {
                        $sportsResult = $this->enrichFromSportsDb(
                            $programme,
                            $matchedEvent,
                            $overwrite,
                            $enrichPosters,
                            $enrichBackdrops,
                            $enrichDescriptions,
                        );
                        if ($sportsResult['changed']) {
                            $result['modified'] = true;
                            $result['sportsdb_matches']++;
                            if ($sportsResult['poster']) {
                                $result['sportsdb_posters']++;
                            }
                        }
                    }
                }

                $enrichedLines[] = json_encode([
                    'channel' => $record['channel'],
                    'programme' => $programme,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($context->cancellationRequested()) {
                    break;
                }
            }
            fclose($handle);
        }

        // Only write the file back if at least one programme was enriched
        if ($result['modified']) {
            $tempPath = $fullPath.'.enriching';
            if (($handle = fopen($tempPath, 'w')) !== false) {
                foreach ($enrichedLines as $line) {
                    fwrite($handle, $line."\n");
                }
                fclose($handle);

                // The original file or directory may have been removed by a cache refresh
                if (is_dir(dirname($fullPath))) {
                    rename($tempPath, $fullPath);
                } else {
                    @unlink($tempPath);
                }
            }
        }

        return $result;
    }

    /**
     * Enrich a single programme with TMDB data.
     * Skips programmes that already have artwork/descriptions (e.g. from Schedules Direct / Gracenote).
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
        bool $enrichPosters,
        bool $enrichBackdrops,
        bool $mapEmbyGenres,
        bool $keywordDetection,
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
        // (e.g. from Schedules Direct / Gracenote during EPG cache generation)
        $hasIcon = ! empty($programme['icon']);
        $hasCategory = ! empty($programme['category']);
        $hasDesc = ! empty($programme['desc']);

        $wantsArtwork = $enrichPosters || $enrichBackdrops;

        if (! $overwrite && (! $wantsArtwork || $hasIcon) && ($hasCategory || ! $enrichCategories) && ($hasDesc || ! $enrichDescriptions)) {
            return $result;
        }

        // ── Keyword detection BEFORE TMDB ──────────────────────────────
        // Detect category from title keywords first (Sports, News, Kids, Documentary).
        // This prevents unnecessary TMDB lookups for live sports, news, etc. and avoids
        // wrong matches like "ALL IN - Die Bundesliga Highlight Show" → film "All In".
        $keywordCategory = null;
        if ($keywordDetection && $mapEmbyGenres && $enrichCategories && ($overwrite || ! $hasCategory)) {
            $keywordCategory = $this->detectCategoryFromTitle($title);
            if ($keywordCategory !== null) {
                $programme['category'] = $keywordCategory;
                $result['category'] = true;
                $result['changed'] = true;
            }
        }

        // If keyword detection identified a non-media category (Sports, News),
        // skip TMDB lookup entirely — these are live broadcasts, not TMDB content.
        if ($keywordCategory !== null && in_array($keywordCategory, ['Sports', 'News'], true)) {
            return $result;
        }

        // ── Extract base title ─────────────────────────────────────────
        // EPG titles are often "Series Name - Episode Title" or "Show: Subtitle".
        // Extract the base show name for fallback TMDB search.
        $baseTitle = $this->extractBaseTitle($title);
        $fullCacheKey = $this->normalizeCacheKey($title);
        $baseCacheKey = ($baseTitle !== $title) ? $this->normalizeCacheKey($baseTitle) : null;

        // Check TMDB lookup cache — try full title first, then base title
        if (isset($cache[$fullCacheKey])) {
            $result['cache_hit'] = true;
            $tmdbData = $cache[$fullCacheKey];
        } elseif ($baseCacheKey !== null && isset($cache[$baseCacheKey])) {
            $result['cache_hit'] = true;
            $tmdbData = $cache[$baseCacheKey];
            $cache[$fullCacheKey] = $tmdbData; // Promote to full key for faster hits
        } else {
            $result['lookup'] = true;

            // Strategy: try full title first (handles compound names like "CSI: Miami",
            // "NCIS: Los Angeles"), then fall back to base title if validation fails.
            $tmdbData = $this->searchTmdbWithValidation($tmdb, $title);
            $matchedViaBase = false;

            if (! $tmdbData && $baseTitle !== $title) {
                $tmdbData = $this->searchTmdbWithValidation($tmdb, $baseTitle);
                $matchedViaBase = $tmdbData !== null;
            }

            // Cache under full title key
            $cache[$fullCacheKey] = $tmdbData;

            // If the match came via the base title, also cache under base key
            // so other episodes of the same show benefit from the cache.
            // e.g. "Sturm der Liebe - Ep A" and "Sturm der Liebe - Ep B"
            // both share the "sturm der liebe" base cache entry.
            if ($matchedViaBase && $baseCacheKey !== null) {
                $cache[$baseCacheKey] = $tmdbData;
            }
        }

        if (! $tmdbData) {
            return $result;
        }

        // Enrich poster/icon
        $posterUrl = $tmdbData['poster_url'] ?? null;
        $backdropUrl = $tmdbData['backdrop_url'] ?? null;

        if ($enrichPosters && $posterUrl && ($overwrite || ! $hasIcon)) {
            $programme['icon'] = $posterUrl;
            $result['poster'] = true;
            $result['changed'] = true;
        }

        // Add backdrop to images array
        if ($enrichBackdrops && $backdropUrl) {
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
        if ($enrichPosters && $posterUrl) {
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
        $mediaType = $tmdbData['_media_type'] ?? null;
        if ($enrichCategories && $genres !== '' && ($overwrite || ! $hasCategory)) {
            if ($mapEmbyGenres) {
                $category = $this->mapToEmbyCategory($genres, $mediaType);
            } else {
                // Take the first genre if comma-separated
                $category = trim(explode(',', $genres)[0]);
            }
            if ($category !== '') {
                $programme['category'] = $category;
                $result['category'] = true;
                $result['changed'] = true;
            }
        } elseif ($mapEmbyGenres && $hasCategory) {
            // Map existing category to Emby genre even if not enriching from TMDB
            $mapped = $this->mapToEmbyCategory($programme['category'], $mediaType);
            if ($mapped !== $programme['category']) {
                $programme['category'] = $mapped;
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

        // Enrichment state stats
        $enrichmentState = $this->loadEnrichmentState();
        $trackedEpgs = count($enrichmentState);
        $trackedFiles = 0;
        $lastEnrichedAt = null;
        foreach ($enrichmentState as $epgState) {
            foreach ($epgState['files'] ?? [] as $fileState) {
                $trackedFiles++;
                $enrichedAt = $fileState['enriched_at'] ?? null;
                if ($enrichedAt && ($lastEnrichedAt === null || $enrichedAt > $lastEnrichedAt)) {
                    $lastEnrichedAt = $enrichedAt;
                }
            }
        }

        // TheSportsDB status
        $settings = $context->settings;
        $sportsEnabled = $settings['enrich_sports_events'] ?? false;
        $sportsApiKey = $settings['sportsdb_api_key'] ?? '';
        $sportsTier = $sportsEnabled
            ? ($sportsApiKey !== '' ? 'premium' : 'free')
            : 'disabled';

        return PluginActionResult::success('EPG Enricher plugin is healthy.', [
            'plugin_id' => 'epg-enricher',
            'tmdb_configured' => $tmdbConfigured,
            'tmdb_cache_entries' => $cacheEntries,
            'sportsdb_status' => $sportsTier,
            'enrichment_state_epgs' => $trackedEpgs,
            'enrichment_state_files' => $trackedFiles,
            'last_enriched_at' => $lastEnrichedAt,
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
        // Strip episode numbers like "S01E03", "(12)", "Folge 5"
        $key = preg_replace('/\s*s\d{1,2}e\d{1,2}\s*/i', '', $key);
        $key = preg_replace('/\s*\(\d{1,4}\)\s*/', '', $key);
        $key = preg_replace('/\s*(?:folge|episode|ep\.?)\s*\d+\s*/i', '', $key);
        // Collapse whitespace
        $key = preg_replace('/\s+/', ' ', $key);

        return trim($key);
    }

    /**
     * Map genre string(s) + TMDB media type to an Emby EPG category that
     * triggers guide color coding.
     *
     * Priority: scan ALL genres for specific categories (News, Sports, Kids)
     * first, then fall back to Movie/Series based on the TMDB media type.
     * This prevents e.g. M*A*S*H (Comedy, War & Politics, Drama) from being
     * labelled "News" just because "War & Politics" appears in its genres.
     *
     * @param  string  $genres  Comma-separated genre string
     * @param  string|null  $mediaType  'tv' or 'movie' from TMDB lookup
     */
    private function mapToEmbyCategory(string $genres, ?string $mediaType): string
    {
        $genreList = array_map('trim', explode(',', $genres));
        $genreKeys = array_map('mb_strtolower', $genreList);

        // Collect all mapped categories across every genre
        $mapped = [];
        foreach ($genreKeys as $key) {
            if (isset(self::EMBY_GENRE_MAP[$key])) {
                $mapped[self::EMBY_GENRE_MAP[$key]] = true;
            }
        }

        // Priority 1: Sports — always unambiguous
        if (isset($mapped['Sports'])) {
            return 'Sports';
        }

        // Priority 2: Kids — check explicit "Kids" mapping OR the kids-adjacent
        // genres (animation, family) which are mapped to Movie in the generic map
        // but should become Kids when the TMDB content is a TV show for children.
        if (isset($mapped['Kids'])) {
            return 'Kids';
        }
        // Animation + Family on TV are almost always kids content
        $kidsAdjacentGenres = ['animation', 'family', 'familie'];
        if ($mediaType === 'tv' && ! empty(array_intersect($genreKeys, $kidsAdjacentGenres))) {
            return 'Kids';
        }

        // Priority 3: News — but only when it's actually news/journalism content.
        // TV series that merely touch political themes (e.g. M*A*S*H with
        // "War & Politics") should NOT be tagged as News.
        if (isset($mapped['News']) && $mediaType !== 'tv') {
            return 'News';
        }

        // Priority 4: Documentary — before the generic media type fallback
        if (isset($mapped['Documentary'])) {
            return 'Documentary';
        }

        // Priority 5: trust the TMDB media type over genre labels.
        // A TV series with genre "Action" should become Series, not Movie.
        if ($mediaType === 'movie') {
            return 'Movie';
        }
        if ($mediaType === 'tv') {
            return 'Series';
        }

        // Priority 6: no media type available — use the first mapped category
        foreach (['Movie', 'Series', 'Music', 'Education', 'News'] as $cat) {
            if (isset($mapped[$cat])) {
                return $cat;
            }
        }

        // Last resort: return the first genre as-is
        return $genreList[0] ?? $genres;
    }

    /**
     * Extract the base series/show name from an EPG title.
     *
     * EPG titles commonly include episode information after a separator:
     *   "Sturm der Liebe - Mysteriöse Botschaft"  → "Sturm der Liebe"
     *   "Grand Hotel - Aufgeflogen"                → "Grand Hotel"
     *   "Tatort: Schwarze Stunden"                 → "Tatort"
     *   "Familie Dr. Kleist - Folge 42"            → "Familie Dr. Kleist"
     *
     * Compound show names like "CSI: Miami" or "NCIS: Los Angeles" are handled
     * by the caller: the full title is searched at TMDB first and only if
     * validation fails does the base title get tried.
     */
    private function extractBaseTitle(string $title): string
    {
        $title = trim($title);

        // Strip trailing episode markers: "(12)", "S01E03", "Folge 5", "Teil 2", etc.
        $cleaned = preg_replace('/\s*\(\d{1,4}\)\s*$/', '', $title);
        $cleaned = preg_replace('/\s*S\d{1,2}E\d{1,2}\s*$/i', '', $cleaned);
        $cleaned = preg_replace('/\s*[-–—]\s*(?:Folge|Episode|Ep\.?|Teil|Part)\s*\d+\s*$/i', '', $cleaned);
        $cleaned = preg_replace('/\s*(?:Folge|Episode|Ep\.?|Teil|Part)\s*\d+\s*$/i', '', $cleaned);
        $cleaned = rtrim(trim($cleaned), '-–— ');

        // Split at " - " / " – " / " — " (most common EPG episode separator)
        if (preg_match('/^(.{2,}?)\s+[-–—]\s+(.+)$/u', $cleaned, $m)) {
            return trim($m[1]);
        }

        // Split at ": " when right part is long (episode subtitle, not abbreviation like "LA")
        if (preg_match('/^(.{2,}?):\s+(.{8,})$/u', $cleaned, $m)) {
            return trim($m[1]);
        }

        return $cleaned;
    }

    /**
     * Search TMDB for a title with result validation.
     *
     * Searches TV series first, then movies. Validates that the returned title
     * actually matches what we searched for (prevents "Sturm der Liebe" → "Fanaa").
     *
     * @return array|null  TMDB data with '_media_type' set, or null if no good match
     */
    private function searchTmdbWithValidation(TmdbService $tmdb, string $searchTitle): ?array
    {
        $searchNorm = mb_strtolower(trim($searchTitle));

        // Try TV series first (EPG = primarily TV content)
        $tvResult = $tmdb->searchTvSeries($searchTitle);
        if ($tvResult && ($tvResult['tmdb_id'] ?? null)) {
            $tmdbTitle = $tvResult['title'] ?? $tvResult['name'] ?? '';
            if ($this->titleMatchScore($searchNorm, $tmdbTitle) >= 0.5) {
                $details = $tmdb->getTvSeriesDetails($tvResult['tmdb_id']);
                if ($details) {
                    $tvResult = array_merge($tvResult, $details);
                }
                $tvResult['_media_type'] = 'tv';

                return $tvResult;
            }
        }

        // Try movie search
        $movieResult = $tmdb->searchMovie($searchTitle, tryFallback: true);
        if ($movieResult && ($movieResult['tmdb_id'] ?? null)) {
            $tmdbTitle = $movieResult['title'] ?? $movieResult['name'] ?? '';
            if ($this->titleMatchScore($searchNorm, $tmdbTitle) >= 0.5) {
                $details = $tmdb->getMovieDetails($movieResult['tmdb_id']);
                if ($details) {
                    $movieResult = array_merge($movieResult, $details);
                }
                $movieResult['_media_type'] = 'movie';

                return $movieResult;
            }
        }

        return null;
    }

    /**
     * Compute a similarity score (0.0–1.0) between an EPG search title and a TMDB result title.
     *
     * Uses multiple strategies to handle real-world mismatches:
     *   - Exact match (after normalization)
     *   - Containment (one title contains the other)
     *   - Token overlap (word-by-word matching)
     *   - Levenshtein distance for short titles
     *
     * Examples:
     *   "sturm der liebe"  vs "Sturm der Liebe"  → 1.0  (exact)
     *   "grand hotel"      vs "Gran Hotel"        → ~0.8 (levenshtein)
     *   "sturm der liebe"  vs "Fanaa"             → ~0.0 (no match)
     *   "tatort"           vs "Tatort"             → 1.0  (exact)
     */
    private function titleMatchScore(string $searchNorm, string $tmdbTitle): float
    {
        $tmdbNorm = mb_strtolower(trim($tmdbTitle));

        if ($searchNorm === '' || $tmdbNorm === '') {
            return 0.0;
        }

        // Exact match
        if ($searchNorm === $tmdbNorm) {
            return 1.0;
        }

        // Containment: one title fully contains the other as a substring.
        // Only boost if the shorter string is a substantial portion of the longer one.
        // This prevents "All In" (6 chars) matching inside "All In - Die Bundesliga Show" (38 chars)
        // while allowing "Sturm der Liebe" (16) inside "Sturm der Liebe - Mysteriöse Botschaft" (39).
        if (str_contains($tmdbNorm, $searchNorm) || str_contains($searchNorm, $tmdbNorm)) {
            $ratio = min(mb_strlen($searchNorm), mb_strlen($tmdbNorm)) / max(mb_strlen($searchNorm), mb_strlen($tmdbNorm));
            if ($ratio >= 0.3) {
                return max(0.6, $ratio);
            }
            // Very short substring in very long title — fall through to token matching
        }

        // Bidirectional token overlap scoring.
        // Forward: how many search tokens appear in the TMDB result?
        // Reverse: how many TMDB tokens appear in the search title?
        // Use the minimum — both sides must have reasonable coverage.
        $searchTokens = preg_split('/[\s\-:.,]+/u', $searchNorm, -1, PREG_SPLIT_NO_EMPTY);
        $tmdbTokens = preg_split('/[\s\-:.,]+/u', $tmdbNorm, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($searchTokens) || empty($tmdbTokens)) {
            return 0.0;
        }

        $significantSearch = array_values(array_filter($searchTokens, fn ($t) => mb_strlen($t) >= 2));
        $significantTmdb = array_values(array_filter($tmdbTokens, fn ($t) => mb_strlen($t) >= 2));

        if (empty($significantSearch) || empty($significantTmdb)) {
            return 0.0;
        }

        // Forward: search → TMDB
        $forwardMatches = $this->countTokenMatches($significantSearch, $significantTmdb);
        $forwardScore = $forwardMatches / count($significantSearch);

        // Reverse: TMDB → search
        $reverseMatches = $this->countTokenMatches($significantTmdb, $significantSearch);
        $reverseScore = $reverseMatches / count($significantTmdb);

        // The final score is the minimum of both directions.
        // "grand hotel aufgeflogen" vs "The Grand Budapest Hotel":
        //   forward 2/3=0.67, reverse 2/3=0.67 → 0.67 (but "budapest" unmatched in BOTH → suspicious)
        //   We penalize further by also considering the ratio of total significant tokens.
        $tokenScore = min($forwardScore, $reverseScore);

        // For very short titles (1-2 words), also check overall Levenshtein
        if (count($significantSearch) <= 2 && mb_strlen($searchNorm) <= 20 && mb_strlen($tmdbNorm) <= 30) {
            $maxLen = max(mb_strlen($searchNorm), mb_strlen($tmdbNorm));
            $dist = levenshtein($searchNorm, $tmdbNorm);
            $levScore = 1.0 - ($dist / $maxLen);

            return max($tokenScore, $levScore);
        }

        return $tokenScore;
    }

    /**
     * Count how many tokens from $source have a match in $target (exact or fuzzy).
     */
    private function countTokenMatches(array $source, array $target): int
    {
        $matches = 0;
        foreach ($source as $token) {
            foreach ($target as $candidate) {
                if ($token === $candidate) {
                    $matches++;

                    break;
                }
                // Fuzzy match for minor spelling differences across languages
                // e.g. "grand" vs "gran", "hotel" vs "hôtel"
                if (mb_strlen($token) >= 4 && mb_strlen($candidate) >= 4) {
                    $maxLen = max(mb_strlen($token), mb_strlen($candidate));
                    if (levenshtein($token, $candidate) <= max(1, (int) ($maxLen * 0.2))) {
                        $matches++;

                        break;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Detect an Emby category from keywords found in the programme title.
     * Used as a fallback when TMDB lookup fails (live sports, news, etc.).
     *
     * @return string|null  The matched category, or null if no keywords match
     */
    private function detectCategoryFromTitle(string $title): ?string
    {
        $titleLower = mb_strtolower($title);

        foreach (self::TITLE_KEYWORD_CATEGORIES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                // Use word boundary matching to avoid false positives
                // e.g. "art" should not match inside "Karting"
                $pattern = '/(?:^|[\s\-\/\|:.,;!?\(\[])' . preg_quote($keyword, '/') . '(?:$|[\s\-\/\|:.,;!?\)\]])/u';
                if (preg_match($pattern, $titleLower)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Fetch sport events from TheSportsDB for a given date.
     * Results are cached per date in the sports-events cache file.
     *
     * For premium API keys, uses eventstv.php (returns channel info + up to 500 results).
     * For free tier, uses eventsday.php (no channel info, limited results).
     *
     * @param  string  $date  Date in Y-m-d format
     * @param  string  $apiKey  API key (empty = free tier key '123')
     * @param  string  $country  Optional country filter for premium TV schedule
     * @param  array<string, array>  $cache  Reference to in-memory cache
     * @return list<array>  Array of event data
     */
    private function fetchSportsDbEventsForDate(
        string $date,
        string $apiKey,
        string $country,
        array &$cache,
    ): array {
        if (isset($cache[$date])) {
            return $cache[$date];
        }

        $key = $apiKey !== '' ? $apiKey : '123';
        $isPremium = $apiKey !== '';
        $events = [];

        try {
            if ($isPremium) {
                // Premium: use TV schedule endpoint (includes channel info)
                $url = "https://www.thesportsdb.com/api/v1/json/{$key}/eventstv.php?d={$date}";
                if ($country !== '') {
                    $url .= '&a=' . urlencode(str_replace(' ', '_', $country));
                }
            } else {
                // Free: use events-by-day endpoint (no channel info, 15 results)
                $url = "https://www.thesportsdb.com/api/v1/json/{$key}/eventsday.php?d={$date}";
            }

            $response = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\n",
                ],
            ]));

            if ($response !== false) {
                $data = json_decode($response, true);
                $eventList = $isPremium
                    ? ($data['tvevents'] ?? [])
                    : ($data['events'] ?? []);

                if (is_array($eventList)) {
                    $events = $eventList;
                }
            }
        } catch (\Throwable) {
            // Silently fail — sports enrichment is best-effort
        }

        $cache[$date] = $events;

        return $events;
    }

    /**
     * Match a sports programme to a TheSportsDB event.
     *
     * Uses fuzzy title matching combined with time-window overlap (±60 min).
     * For premium users with channel data, also considers channel name similarity.
     *
     * @param  array  $programme  The EPG programme data
     * @param  list<array>  $events  TheSportsDB events for the day
     * @return array|null  The best matching event, or null
     */
    private function matchSportsEvent(array $programme, array $events): ?array
    {
        if (empty($events)) {
            return null;
        }

        $progTitle = mb_strtolower($programme['title'] ?? '');
        $progStart = $programme['start'] ?? null;

        if ($progTitle === '') {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0;

        foreach ($events as $event) {
            $eventName = mb_strtolower($event['strEvent'] ?? '');
            if ($eventName === '') {
                continue;
            }

            $score = 0;

            // Token-based title matching: check how many words of the event name
            // appear in the programme title (or vice versa)
            $eventTokens = preg_split('/[\s\-_vs\.]+/', $eventName, -1, PREG_SPLIT_NO_EMPTY);
            $matchedTokens = 0;
            foreach ($eventTokens as $token) {
                if (mb_strlen($token) >= 3 && str_contains($progTitle, $token)) {
                    $matchedTokens++;
                }
            }

            if (count($eventTokens) > 0) {
                $tokenScore = $matchedTokens / count($eventTokens);
            } else {
                $tokenScore = 0;
            }

            // Require at least 40% token overlap to consider this a possible match
            if ($tokenScore < 0.4) {
                continue;
            }
            $score += $tokenScore * 60;  // Up to 60 points for title match

            // Time proximity scoring (if both have timestamps)
            $eventTimestamp = $event['strTimestamp'] ?? $event['strTimeStamp'] ?? null;
            $eventDate = $event['dateEvent'] ?? null;
            $eventTime = $event['strTime'] ?? null;
            if (! $eventTimestamp && $eventDate && $eventTime) {
                $eventTimestamp = $eventDate . ' ' . $eventTime;
            }

            if ($progStart && $eventTimestamp) {
                try {
                    $progTime = Carbon::parse($progStart);
                    $eventTimeCarbon = Carbon::parse($eventTimestamp);
                    $diffMinutes = abs($progTime->diffInMinutes($eventTimeCarbon));

                    if ($diffMinutes <= 60) {
                        // Within 60 min window: 40 points at exact match, down to 0 at 60 min
                        $score += 40 * (1 - $diffMinutes / 60);
                    } else {
                        // Too far apart — penalize heavily
                        $score -= 20;
                    }
                } catch (\Throwable) {
                    // Ignore parse errors
                }
            }

            // Sport type bonus: if the event sport matches a keyword in the title
            $sport = mb_strtolower($event['strSport'] ?? '');
            if ($sport !== '' && str_contains($progTitle, $sport)) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $event;
            }
        }

        // Only return if we have a reasonable confidence score
        return $bestScore >= 50 ? $bestMatch : null;
    }

    /**
     * Enrich a programme with TheSportsDB event data.
     *
     * @return array{changed: bool, poster: bool, description: bool}
     */
    private function enrichFromSportsDb(
        array &$programme,
        array $event,
        bool $overwrite,
        bool $enrichPosters,
        bool $enrichBackdrops,
        bool $enrichDescriptions,
    ): array {
        $result = ['changed' => false, 'poster' => false, 'description' => false];

        // Artwork enrichment
        $posterUrl = $event['strEventThumb'] ?? $event['strEventPoster'] ?? $event['strThumb'] ?? null;
        $bannerUrl = $event['strEventBanner'] ?? null;

        $hasIcon = ! empty($programme['icon']);

        if ($enrichPosters && $posterUrl && ($overwrite || ! $hasIcon)) {
            $programme['icon'] = $posterUrl;
            $result['poster'] = true;
            $result['changed'] = true;
        }

        if ($enrichBackdrops && $bannerUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($bannerUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $bannerUrl,
                    'type' => 'backdrop',
                    'width' => 1920,
                    'height' => 1080,
                    'orient' => 'L',
                    'size' => 3,
                ];
                $result['changed'] = true;
            }
        }

        if ($enrichPosters && $posterUrl) {
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

        // Description enrichment — build from event metadata
        $hasDesc = ! empty($programme['desc']);
        if ($enrichDescriptions && ($overwrite || ! $hasDesc)) {
            $parts = [];
            $eventName = $event['strEvent'] ?? '';
            $sport = $event['strSport'] ?? '';
            $league = $event['strLeague'] ?? '';
            $season = $event['strSeason'] ?? '';
            $venue = $event['strVenue'] ?? '';

            if ($sport !== '') {
                $parts[] = $sport;
            }
            if ($league !== '') {
                $parts[] = $league;
            }
            if ($season !== '') {
                $parts[] = "Season {$season}";
            }
            if ($venue !== '') {
                $parts[] = $venue;
            }

            if (! empty($parts)) {
                $programme['desc'] = implode(' · ', $parts);
                $result['description'] = true;
                $result['changed'] = true;
            }
        }

        return $result;
    }

    /**
     * Override the TmdbService's protected language property via reflection.
     */
    private function setTmdbLanguage(TmdbService $tmdb, string $language): void
    {
        $property = new ReflectionProperty($tmdb, 'language');
        $property->setValue($tmdb, $language);
    }

    /**
     * Load enrichment state manifest from disk.
     *
     * @return array<string, array>
     */
    private function loadEnrichmentState(): array
    {
        $path = 'plugin-data/epg-enricher/enrichment-state.json';
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $data = json_decode(Storage::disk('local')->get($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Save enrichment state manifest to disk.
     *
     * @param  array<string, array>  $state
     */
    private function saveEnrichmentState(array $state): void
    {
        Storage::disk('local')->makeDirectory('plugin-data/epg-enricher');
        Storage::disk('local')->put(
            'plugin-data/epg-enricher/enrichment-state.json',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Compute a hash over enrichment-relevant settings to detect config changes.
     */
    private function computeSettingsHash(array $settings): string
    {
        $relevant = [
            'enrich_from_tmdb' => $settings['enrich_from_tmdb'] ?? true,
            'overwrite_existing' => $settings['overwrite_existing'] ?? false,
            'enrich_categories' => $settings['enrich_categories'] ?? true,
            'enrich_descriptions' => $settings['enrich_descriptions'] ?? true,
            'enrich_posters' => $settings['enrich_posters'] ?? true,
            'enrich_backdrops' => $settings['enrich_backdrops'] ?? true,
            'map_emby_genres' => $settings['map_emby_genres'] ?? false,
            'keyword_category_detection' => $settings['keyword_category_detection'] ?? true,
            'enrich_sports_events' => $settings['enrich_sports_events'] ?? false,
            'sportsdb_api_key' => $settings['sportsdb_api_key'] ?? '',
            'sportsdb_country' => $settings['sportsdb_country'] ?? '',
            'tmdb_language' => $settings['tmdb_language'] ?? '',
        ];

        return md5(json_encode($relevant));
    }

    /**
     * Compute a hash over sorted target channel IDs to detect mapping changes.
     *
     * @param  array<string>  $channelIds
     */
    private function computeChannelsHash(array $channelIds): string
    {
        $sorted = $channelIds;
        sort($sorted);

        return md5(json_encode($sorted));
    }

    /**
     * Clear the enrichment state file, forcing full re-enrichment on next run.
     */
    private function clearEnrichmentState(PluginExecutionContext $context): PluginActionResult
    {
        $path = 'plugin-data/epg-enricher/enrichment-state.json';

        if (! Storage::disk('local')->exists($path)) {
            return PluginActionResult::success('No enrichment state to clear.');
        }

        $state = $this->loadEnrichmentState();
        $epgCount = count($state);
        $fileCount = 0;
        foreach ($state as $epgState) {
            $fileCount += count($epgState['files'] ?? []);
        }

        Storage::disk('local')->delete($path);

        $context->info("Cleared enrichment state: {$epgCount} EPG(s), {$fileCount} tracked file(s).");

        return PluginActionResult::success(
            "Enrichment state cleared. Next run will re-process all files ({$epgCount} EPG(s), {$fileCount} tracked file(s)).",
            ['epgs_cleared' => $epgCount, 'files_cleared' => $fileCount]
        );
    }
}
