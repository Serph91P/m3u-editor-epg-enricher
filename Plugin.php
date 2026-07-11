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
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ReflectionProperty;

class Plugin implements EpgProcessorPluginInterface, HookablePluginInterface
{
    private const DATE_FILE_HEARTBEAT_INTERVAL_SECONDS = 300;

    /**
     * Bumped whenever the enrichment output for the SAME inputs changes.
     *
     * Mixed into computeSettingsHash() so that updating the plugin code automatically
     * invalidates per-file enrichment state. Users get the new behaviour on the next
     * run without having to manually delete enrichment-state.json or toggle overwrite.
     *
     * Bump this when you change:
     *   - which fields are written to $programme[] (icon, images[], category, desc, etc.)
     *   - the structure/ordering/values of $programme['images'][] entries
     *   - TMDB matching/scoring logic or category mapping
     *   - episode resolution / still selection
     *
     * Do NOT bump for: pure refactors, logging changes, comment edits, perf-only
     * changes that don't affect output.
     *
     * Format: 'YYYY.MM.DD-shortlabel'. Date is informational; the comparison is exact-string.
     */
    private const ENRICHMENT_LOGIC_VERSION = '2026.07.10-v1.13.1';
    /**
     * Canonical EPG category vocabulary used by major IPTV-style clients.
     *
     * The 8 names below are the union of what Emby, Jellyfin, Kodi (PVR), Plex,
     * and TVHeadend recognize for color-coding / filtering in their guide UIs:
     *
     *   Color-coded in most clients: Movie, News, Sports, Kids
     *   Plain (no color, but filterable): Series, Documentary, Music, Education
     *
     * Adding new category strings here is a breaking change for downstream guide
     * UIs (unrecognized values render as "Other" or get dropped by some clients).
     * If a TMDB genre does not fit the 8 categories above, map it to the closest
     * existing one. Do NOT introduce new ones without verifying client support.
     *
     * For ambiguous genres (e.g. "Action" can be a movie or TV series) the TMDB
     * media_type overrides the static mapping; see {@see mapToEpgCategory()}.
     *
     * @var array<string, string>
     */
    private const array EPG_CATEGORY_MAP = [
        // ── News (color-coded) ──────────────────────────────────────
        'news' => 'News',
        'nachrichten' => 'News',
        'journalism' => 'News',
        'journalismus' => 'News',
        'current affairs' => 'News',
        'tagesschau' => 'News',
        'heute' => 'News',
        'wetter' => 'News',
        'politik' => 'News',
        'politics' => 'News',
        'magazin' => 'News',
        'business' => 'News',
        'wirtschaft' => 'News',
        'finance' => 'News',

        // ── Sports (color-coded) ────────────────────────────────────
        'sport' => 'Sports',
        'sports' => 'Sports',
        'basketball' => 'Sports',
        'baseball' => 'Sports',
        'football' => 'Sports',
        'tennis' => 'Sports',
        'golf' => 'Sports',
        'cycling' => 'Sports',
        'radsport' => 'Sports',
        'boxing' => 'Sports',
        'boxen' => 'Sports',
        'mma' => 'Sports',
        'ufc' => 'Sports',
        'darts' => 'Sports',
        'snooker' => 'Sports',
        'billard' => 'Sports',
        'eishockey' => 'Sports',
        'hockey' => 'Sports',
        'handball' => 'Sports',
        'volleyball' => 'Sports',
        'ski' => 'Sports',
        'biathlon' => 'Sports',
        'wintersport' => 'Sports',
        'leichtathletik' => 'Sports',
        'athletics' => 'Sports',
        'olympia' => 'Sports',
        'olympics' => 'Sports',
        'esports' => 'Sports',
        'e-sport' => 'Sports',

        // ── Kids / Children (color-coded) ───────────────────────────
        'kids' => 'Kids',
        'kinder' => 'Kids',
        'children' => 'Kids',
        'childrens' => 'Kids',
        'disney' => 'Kids',
        'cartoon' => 'Kids',
        'animated kids' => 'Kids',
        'preschool' => 'Kids',
        'vorschule' => 'Kids',
        'jugend' => 'Kids',
        'youth' => 'Kids',
        'nickelodeon' => 'Kids',
        'kika' => 'Kids',

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
        'spielfilm' => 'Movie',
        'film' => 'Movie',
        'movie' => 'Movie',
        'romantik' => 'Movie',
        'romanze' => 'Movie',
        'erotik' => 'Movie',
        'erotic' => 'Movie',
        'martial arts' => 'Movie',
        'kampfsport-film' => 'Movie',
        'historical drama' => 'Movie',
        'historiendrama' => 'Movie',
        'biopic' => 'Movie',
        'biografie' => 'Movie',
        'biography' => 'Movie',
        'musical' => 'Movie',

        // Shared genres: mapped to Movie, overridden to Series by media type
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

        // ── Series (no color; TV-exclusive TMDB genres) ────────────
        'action & adventure' => 'Series',
        'action & abenteuer' => 'Series',
        'sci-fi & fantasy' => 'Series',
        'war & politics' => 'Series',
        'krieg & politik' => 'Series',
        'soap' => 'Series',
        'reality' => 'Series',
        'talk' => 'Series',
        'serie' => 'Series',
        'series' => 'Series',
        'tv-serie' => 'Series',
        'tv serie' => 'Series',
        'sitcom' => 'Series',
        'sketch' => 'Series',
        'telenovela' => 'Series',
        'soap opera' => 'Series',
        'mini-series' => 'Series',
        'miniserie' => 'Series',
        'limited series' => 'Series',
        'anime' => 'Series',
        'game show' => 'Series',
        'gameshow' => 'Series',
        'spielshow' => 'Series',
        'quiz' => 'Series',
        'quizshow' => 'Series',
        'cooking show' => 'Series',
        'kochshow' => 'Series',
        'lifestyle' => 'Series',
        'reality-tv' => 'Series',
        'doku-soap' => 'Series',

        // ── Documentary (no color) ──────────────────────────────────
        'documentary' => 'Documentary',
        'dokumentarfilm' => 'Documentary',
        'dokumentation' => 'Documentary',
        'history' => 'Documentary',
        'historie' => 'Documentary',
        'doku' => 'Documentary',
        'docu' => 'Documentary',
        'reportage' => 'Documentary',
        'report' => 'Documentary',
        'natur' => 'Documentary',
        'nature' => 'Documentary',
        'tier' => 'Documentary',
        'tiere' => 'Documentary',
        'animals' => 'Documentary',
        'wildlife' => 'Documentary',
        'wissenschaft' => 'Documentary',
        'science' => 'Documentary',
        'technik' => 'Documentary',
        'technology' => 'Documentary',
        'reise' => 'Documentary',
        'travel' => 'Documentary',
        'true crime' => 'Documentary',
        'crime documentary' => 'Documentary',
        'history channel' => 'Documentary',
        'geschichte' => 'Documentary',

        // ── Music (no color) ────────────────────────────────────────
        'music' => 'Music',
        'musik' => 'Music',
        'konzert' => 'Music',
        'concert' => 'Music',
        'musikvideo' => 'Music',
        'music video' => 'Music',
        'oper' => 'Music',
        'opera' => 'Music',
        'klassik' => 'Music',
        'classical' => 'Music',

        // ── Education (no color) ────────────────────────────────────
        'education' => 'Education',
        'bildung' => 'Education',
        'lehrfilm' => 'Education',
        'tutorial' => 'Education',
        'sprachkurs' => 'Education',
        'language' => 'Education',
        'wissensmagazin' => 'Education',
    ];

    /**
     * Detailed Kodi guide genre labels from m3u-editor issue 347.
     *
     * These are intentionally separate from the compact Emby compatible
     * category map above. Kodi skins can color many more genre labels, while
     * Emby style guide coloring works best with the compact category set.
     *
     * @var array<string, string>
     */
    private const array KODI_GUIDE_GENRE_MAP = [
        'sport' => 'Sport',
        'sports' => 'Sport',
        'sports event' => 'Sports Event',
        'sports talk' => 'Sports Talk',
        'archery' => 'Archery',
        'rodeo' => 'Rodeo',
        'card games' => 'Card Games',
        'martial arts' => 'Martial Arts',
        'basketball' => 'Basketball',
        'baseball' => 'Baseball',
        'hockey' => 'Hockey',
        'football' => 'Football',
        'boxing' => 'Boxing',
        'golf' => 'Golf',
        'auto racing' => 'Auto Racing',
        'motorsport' => 'Auto Racing',
        'playoff sports' => 'Playoff Sports',
        'hunting' => 'Hunting',
        'gymnastics' => 'Gymnastics',
        'shooting' => 'Shooting',
        'sports non-event' => 'Sports non-event',
        'news' => 'News',
        'public affairs' => 'Public Affairs',
        'newsmagazine' => 'Newsmagazine',
        'politics' => 'Public Affairs',
        'entertainment' => 'Entertainment',
        'community' => 'Community',
        'talk' => 'Talk',
        'interview' => 'Interview',
        'weather' => 'Weather',
        'comedy' => 'Comedy',
        'comedy-drama' => 'Comedy-Drama',
        'comedy drama' => 'Comedy-Drama',
        'romance-comedy' => 'Romance-Comedy',
        'romance comedy' => 'Romance-Comedy',
        'sitcom' => 'Sitcom',
        'comedy-romance' => 'Comedy-Romance',
        'comedy romance' => 'Comedy-Romance',
        'musical' => 'Musical',
        'music' => 'Music',
        'musical comedy' => 'Musical Comedy',
        'documentary' => 'Documentary',
        'history' => 'History',
        'biography' => 'Biography',
        'educational' => 'Educational',
        'education' => 'Educational',
        'animals' => 'Animals',
        'nature' => 'Nature',
        'health' => 'Health',
        'animation' => 'Animation',
        'animated' => 'Animated',
        'anime' => 'Anime',
        'children' => 'Children',
        'kids' => 'Children',
        'cartoon' => 'Cartoon',
        'family' => 'Family',
        'movie' => 'Movie',
        'film' => 'Movie',
        'drama' => 'Drama',
        'romance' => 'Romance',
        'historical drama' => 'Historical Drama',
        'outdoors' => 'Outdoors',
        'special' => 'Special',
        'reality' => 'Reality',
        'suspense' => 'Suspense',
        'horror' => 'Horror',
        'horror suspense' => 'Horror Suspense',
        'paranormal' => 'Paranormal',
        'thriller' => 'Thriller',
        'fantasy' => 'Fantasy',
        'action' => 'Action',
        'adventure' => 'Adventure',
        'action and adventure' => 'Action and Adventure',
        'action adventure' => 'Action and Adventure',
        'action & adventure' => 'Action and Adventure',
        'crime' => 'Crime',
        'crime drama' => 'Crime Drama',
        'mystery' => 'Mystery',
        'science fiction' => 'Science Fiction',
        'sci-fi' => 'Science Fiction',
        'sci fi' => 'Science Fiction',
        'series' => 'Series',
        'western' => 'Western',
        'soap' => 'Soap',
        'variety' => 'Variety',
        'war' => 'War',
        'law' => 'Law',
        'adults only' => 'Adults Only',
        'auto' => 'Auto',
        'collectibles' => 'Collectibles',
        'travel' => 'Travel',
        'shopping' => 'Shopping',
        'house garden' => 'House Garden',
        'home and garden' => 'Home and Garden',
        'gardening' => 'Gardening',
        'fitness health' => 'Fitness Health',
        'fitness' => 'Fitness',
        'home improvement' => 'Home Improvement',
        'how-to' => 'How-To',
        'how to' => 'How-To',
        'cooking' => 'Cooking',
        'fashion' => 'Fashion',
        'aviation' => 'Aviation',
        'dance' => 'Dance',
        'auction' => 'Auction',
        'art' => 'Art',
        'exercise' => 'Exercise',
        'parenting' => 'Parenting',
        'consumer' => 'Consumer',
        'game show' => 'Game Show',
        'gameshow' => 'Game Show',
        'other' => 'Other',
        'unknown' => 'Unknown',
        'religious' => 'Religious',
        'anthology' => 'Anthology',
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
            // Additional broadcasters / disciplines
            'sport-clips', 'sky sport', 'dazn', 'eurosport',
            'reitsport', 'pferderennen', 'pferdesport',
            'segeln', 'rudern', 'kanu', 'turnen', 'gymnastik',
            'fechten', 'judo', 'karate', 'taekwondo',
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
            // Additional EPG title patterns
            'zib', 'punkt 12', 'mittagsmagazin', 'logo!',
            'maybrit illner',
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
            // Additional kids EPG patterns
            'sandmann', 'sendung mit der maus', 'maus', 'pixi', 'heidi',
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
            // Additional documentary title patterns
            'planet wissen', 'welt der wunder', 'auf entdeckungsreise',
            'expeditionen', 'mission erde', 'zdfinfo doku',
        ],
    ];

    /**
     * Additional title keywords that strongly indicate episodic TV content.
     *
     * These are used as a tiebreaker to force TV-only TMDB lookup for
     * commonly misclassified programme titles.
     *
     * @var list<string>
     */
    private const array EPISODIC_TITLE_KEYWORDS = [
        'soko ',
        'tatort',
        'polizeiruf',
        'watzmann ermittelt',
        'in aller freundschaft',
        'sturm der liebe',
        'großstadtrevier',
        'grossstadtrevier',
        'rote rosen',
        'gute zeiten schlechte zeiten',
        'gzsz',
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
        // Backcompat: old key map_emby_genres still honored for users who upgrade from <2026.05.
        $mapGenresToEpgCategories = $settings['map_genres_to_epg_categories']
            ?? $settings['map_emby_genres']
            ?? false;
        $mapGenresToKodiGuideGenres = $settings['map_genres_to_kodi_guide_genres'] ?? false;
        $keywordDetection = $settings['keyword_category_detection'] ?? true;
        $enrichEpisodeDetails = $settings['enrich_episode_details'] ?? true;

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
        $tmdbSeasonCache = $this->loadTmdbSeasonCache();
        $imagesCache = $this->loadTmdbImagesCache();

        // If language changed, the TMDB lookup cache contains results in the old
        // language. Clear it so titles are re-searched with the new language.
        $storedLanguage = $tmdbCache['__language'] ?? null;
        $currentLanguage = $tmdbLanguage !== '' ? $tmdbLanguage : '__global';
        if ($storedLanguage !== null && $storedLanguage !== $currentLanguage) {
            $context->heartbeat('TMDB language changed - clearing lookup cache for fresh results.');
            $tmdbCache = [];
            $tmdbSeasonCache = [];
        }
        $tmdbCache['__language'] = $currentLanguage;
        $tmdbSeasonCache['__language'] = $currentLanguage;

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

        $cacheDir = $this->getActiveCacheDir($epg);
        if ($cacheDir === null) {
            return PluginActionResult::failure('Could not resolve EPG cache directory.');
        }
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
        ];

        $newFileStates = [];

        while ($currentDate->lte($endDate)) {
            $dayIndex++;
            $dateStr = $currentDate->format('Y-m-d');
            $jsonlFile = "{$cacheDir}/programmes-{$dateStr}.jsonl";
            $fileName = "programmes-{$dateStr}.jsonl";

            if ($context->cancellationRequested()) {
                $this->saveTmdbCache($tmdbCache);
                $this->saveTmdbSeasonCache($tmdbSeasonCache);
                $this->saveTmdbImagesCache($imagesCache);
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
                $context->info("Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - file missing");
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
                $context->info("Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - unchanged source data");
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

            $context->info("Processing {$dateStr} ({$dayIndex}/{$totalDays})...");
            $context->heartbeat(
                "Processing {$dateStr} ({$dayIndex}/{$totalDays})...",
                progress: (int) ((($dayIndex - 1) / $totalDays) * 100)
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
                $mapGenresToEpgCategories,
                $mapGenresToKodiGuideGenres,
                $keywordDetection,
                $enrichEpisodeDetails,
                $tmdbSeasonCache,
                $imagesCache,
                $context,
                $dayIndex,
                $totalDays,
                $dateStr,
            );

            $stats['programmes_processed'] += $result['processed'];
            $stats['programmes_updated'] += $result['updated'];
            $stats['programmes_already_enriched'] += $result['already_enriched'];
            $stats['posters_added'] += $result['posters'];
            $stats['categories_added'] += $result['categories'];
            $stats['descriptions_added'] += $result['descriptions'];
            $stats['tmdb_lookups'] += $result['lookups'];
            $stats['tmdb_cache_hits'] += $result['cache_hits'];
            if ($result['cancelled']) {
                $this->saveTmdbCache($tmdbCache);
                $this->saveTmdbSeasonCache($tmdbSeasonCache);
                $this->saveTmdbImagesCache($imagesCache);
                $enrichmentState[$stateKey] = [
                    'settings_hash' => $settingsHash,
                    'channels_hash' => $channelsHash,
                    'files' => array_merge($fileStates, $newFileStates),
                ];
                $this->saveEnrichmentState($enrichmentState);

                return PluginActionResult::cancelled('Enrichment cancelled.', $stats);
            }
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
        $this->saveTmdbSeasonCache($tmdbSeasonCache);
        $this->saveTmdbImagesCache($imagesCache);

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
     * @return array{enriched: int, skipped: int, posters: int, categories: int, descriptions: int, lookups: int, cache_hits: int, modified: bool, cancelled: bool}
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
        bool $mapGenresToEpgCategories,
        bool $mapGenresToKodiGuideGenres,
        bool $keywordDetection,
        bool $enrichEpisodeDetails,
        array &$tmdbSeasonCache,
        array &$imagesCache,
        PluginExecutionContext $context,
        int $dayIndex = 1,
        int $totalDays = 1,
        string $date = '',
        ?callable $clock = null,
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
            'modified' => false,
            'cancelled' => false,
        ];

        $fullPath = Storage::disk('local')->path($jsonlFile);
        $targetSet = array_flip($targetChannelIds);
        $fileSize = filesize($fullPath);
        $clock ??= static fn (): float => microtime(true);
        $lastHeartbeatAt = $clock();

        // Read all records, enrich only targeted channels
        $enrichedLines = [];
        if (($handle = fopen($fullPath, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                if ($context->cancellationRequested()) {
                    fclose($handle);
                    $result['cancelled'] = true;

                    return $result;
                }

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
                    $mapGenresToEpgCategories,
                    $mapGenresToKodiGuideGenres,
                    $keywordDetection,
                    $enrichEpisodeDetails,
                    $tmdbSeasonCache,
                    $imagesCache,
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

                $enrichedLines[] = json_encode([
                    'channel' => $record['channel'],
                    'programme' => $programme,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $now = $clock();
                if ($now - $lastHeartbeatAt >= self::DATE_FILE_HEARTBEAT_INTERVAL_SECONDS) {
                    $fileProgress = $fileSize > 0 ? min(1, ftell($handle) / $fileSize) : 1;
                    $progress = (int) ((($dayIndex - 1 + $fileProgress) / $totalDays) * 100);
                    $context->heartbeat(
                        "Processing {$date} ({$dayIndex}/{$totalDays}) - {$result['processed']} programmes processed",
                        progress: $progress,
                    );
                    $lastHeartbeatAt = $now;
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
        bool $mapGenresToEpgCategories,
        bool $mapGenresToKodiGuideGenres,
        bool $keywordDetection,
        bool $enrichEpisodeDetails,
        array &$tmdbSeasonCache,
        array &$imagesCache,
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
        $hasCategory = ! empty($programme['category']);
        $hasDesc = ! empty($programme['desc']);
        $existingCategory = trim((string) ($programme['category'] ?? ''));
        $trustedLandscapeIcon = $this->hasTrustedLandscapeIcon($programme);

        $wantsArtwork = $enrichPosters || $enrichBackdrops;

        $seriesSignals = $this->detectSeriesSignals($programme);
        $hasEpisodicTitleKeyword = $this->hasEpisodicTitleKeyword($title);
        $isSeriesEpisode = $seriesSignals['is_series_episode'] || $hasEpisodicTitleKeyword;
        $hasEpisodicProviderId = (bool) preg_match('/^(EP|SH)\d+/i', trim((string) ($programme['episode_num'] ?? '')));
        $hasStrongSeriesSignals = $hasEpisodicTitleKeyword
            || $hasEpisodicProviderId
            || $seriesSignals['season'] !== null
            || $seriesSignals['episode'] !== null
            || $seriesSignals['confidence'] === 'high';
        $isSeriesLikeCategory = in_array(mb_strtolower($existingCategory), ['series', 'kids'], true);
        $categoryMappingEnabled = $mapGenresToEpgCategories || $mapGenresToKodiGuideGenres;
        $needsCategoryFix = $categoryMappingEnabled
            && $enrichCategories
            && $hasCategory
            && $isSeriesEpisode
            && ! $isSeriesLikeCategory;

        if (! $overwrite
            && (! $wantsArtwork || $trustedLandscapeIcon)
            && ($hasCategory || ! $enrichCategories)
            && ($hasDesc || ! $enrichDescriptions)
            && ! $needsCategoryFix) {
            return $result;
        }

        // ── Keyword detection BEFORE TMDB ──────────────────────────────
        // Detect category from title keywords first (Sports, News, Kids, Documentary).
        // This prevents unnecessary TMDB lookups for live sports, news, etc. and avoids
        // wrong matches like "ALL IN - Die Bundesliga Highlight Show" → film "All In".
        $keywordCategory = null;
        if ($keywordDetection && $categoryMappingEnabled && $enrichCategories && ($overwrite || ! $hasCategory || $needsCategoryFix)) {
            $keywordCategory = $this->detectCategoryFromTitle($title);
            if ($keywordCategory !== null) {
                $programme['category'] = $mapGenresToKodiGuideGenres
                    ? $this->mapToKodiGuideGenre($keywordCategory, null)
                    : $keywordCategory;
                $result['category'] = true;
                $result['changed'] = true;
            }
        }

        // If keyword detection identified a non-media category (Sports, News),
        // skip TMDB lookup entirely; these are live broadcasts, not TMDB content.
        if ($keywordCategory !== null && in_array($keywordCategory, ['Sports', 'News'], true)) {
            return $result;
        }

        // ── Extract base title ─────────────────────────────────────────
        // EPG titles are often "Series Name - Episode Title" or "Show: Subtitle".
        // Extract the base show name for fallback TMDB search.
        $baseExtracted = $this->extractBaseTitle($title);
        $baseTitle = $baseExtracted['title'];
        $year = $baseExtracted['year'];
        if ($year === null) {
            $desc = trim((string) ($programme['desc'] ?? ''));
            if ($desc !== '') {
                // Look for a 4-digit year token anywhere in desc.
                // Use the FIRST occurrence (production year typically appears early
                // in EPG descriptions: "USA 2010, Action mit ..." / "Spielfilm, 2010").
                if (preg_match('/\b(19\d{2}|20\d{2})\b/', $desc, $ym)) {
                    $candidate = (int) $ym[1];
                    $currentYear = (int) date('Y');
                    // Sanity bound: do not accept future years beyond current+2.
                    if ($candidate >= 1900 && $candidate <= $currentYear + 2) {
                        $year = $candidate;
                    }
                }
            }
        }
        $forcedMediaType = $hasStrongSeriesSignals ? 'tv' : null;
        $description = trim((string) ($programme['desc'] ?? ''));
        $lookupEvidence = [
            'logic' => self::ENRICHMENT_LOGIC_VERSION,
            'title' => $this->normalizeCacheKey($title),
            'base_title' => $this->normalizeCacheKey($baseTitle),
            'year' => $year,
            'forced_media_type' => $forcedMediaType,
            'description' => $this->normalizeIdentityText($description),
            'episodic' => $isSeriesEpisode,
            'strong_series_signals' => $hasStrongSeriesSignals,
            'episodic_keyword' => $hasEpisodicTitleKeyword,
            'series_confidence' => $seriesSignals['confidence'],
            'season' => $seriesSignals['season'],
            'episode' => $seriesSignals['episode'],
        ];
        $evidenceHash = hash('sha256', json_encode($lookupEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $fullCacheKey = $this->normalizeCacheKey($title).'|'.$evidenceHash;
        $baseCacheKey = ($baseTitle !== $title) ? $this->normalizeCacheKey($baseTitle).'|'.$evidenceHash : null;

        // Check TMDB lookup cache: try full title first, then base title
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
            $tmdbData = $this->searchTmdbWithValidation($tmdb, $title, $forcedMediaType, $year, $description);
            $matchedViaBase = false;

            if (! $tmdbData && $baseTitle !== $title) {
                $tmdbData = $this->searchTmdbWithValidation($tmdb, $baseTitle, $forcedMediaType, $year, $description);
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
            if (! $result['cache_hit']) {
                $this->logMissedTitle($title, $baseTitle, $year, $forcedMediaType);
            }

            if ($categoryMappingEnabled && $enrichCategories && ($overwrite || ! $hasCategory || $needsCategoryFix) && $isSeriesEpisode) {
                $programme['category'] = $mapGenresToKodiGuideGenres
                    ? $this->mapToKodiGuideGenre('Series', 'tv')
                    : 'Series';
                $result['category'] = true;
                $result['changed'] = true;
            }

            return $result;
        }

        // Enrich poster/icon
        $posterUrl = $tmdbData['poster_url'] ?? null;
        $backdropUrl = $tmdbData['backdrop_url'] ?? null;
        $mediaType = $tmdbData['_media_type'] ?? null;

        // Primary <icon> in XMLTV: prefer landscape (backdrop) over portrait (poster).
        // Reason: emby/jellyfin/plex/tvheadend xmltv importers read only the FIRST <icon>
        // and ignore non-standard type/orient/size attributes. Their EPG grid cells are
        // landscape, so a portrait poster as primary icon gets cropped/stretched.
        // For tv episodes we'll override with the episode still further below if available.
        $primaryIconUrl = null;
        if ($enrichBackdrops && $backdropUrl) {
            $primaryIconUrl = $backdropUrl;
        }

        if ($primaryIconUrl !== null
            && ($overwrite || ! $trustedLandscapeIcon)
            && ($programme['icon'] ?? null) !== $primaryIconUrl) {
            $programme['icon'] = $primaryIconUrl;
            $result['poster'] = true;
            $result['changed'] = true;
        }

        // Add backdrop to images array (size=1: primary landscape for EPG grid)
        if ($enrichBackdrops && $backdropUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($backdropUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $backdropUrl,
                    'type' => 'backdrop',
                    'width' => 1920,
                    'height' => 1080,
                    'orient' => 'L',
                    'size' => 1,
                ];
                $result['changed'] = true;
            }
        }

        // Add poster to images array (size=2: portrait for info/details views)
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

        // Erweiterte images-pipeline: hole zusätzliche varianten + logo
        if (($enrichPosters || $enrichBackdrops) && ! empty($tmdbData['tmdb_id']) && ! empty($mediaType)) {
            $creds = $this->getTmdbCredentials();
            if ($creds !== null) {
                $imageSet = $this->fetchTmdbImages((int) $tmdbData['tmdb_id'], $mediaType, $imagesCache);
                if ($imageSet !== null) {
                    $candidates = $this->selectImageSet($imageSet, $creds['language']);
                    $existingUrls = array_column($programme['images'] ?? [], 'url');
                    foreach ($candidates as $img) {
                        if (in_array($img['url'], $existingUrls, true)) {
                            continue;
                        }
                        if ($img['type'] === 'poster' && ! $enrichPosters) {
                            continue;
                        }
                        if ($img['type'] === 'backdrop' && ! $enrichBackdrops) {
                            continue;
                        }
                        // logos sind opt-in via enrichBackdrops (L-orient assets)
                        if ($img['type'] === 'logo' && ! $enrichBackdrops) {
                            continue;
                        }

                        $programme['images'][] = $img;
                        $existingUrls[] = $img['url'];
                        $result['changed'] = true;
                    }
                }
            }
        }

        // Enrich category/genre
        $genres = $tmdbData['genres'] ?? '';
        if ($enrichCategories && $genres !== '' && ($overwrite || ! $hasCategory)) {
            if ($mapGenresToKodiGuideGenres) {
                $category = $this->mapToKodiGuideGenre($genres, $mediaType);
            } elseif ($mapGenresToEpgCategories) {
                $category = $this->mapToEpgCategory($genres, $mediaType);
            } else {
                // Take the first genre if comma-separated
                $category = trim(explode(',', $genres)[0]);
            }
            if ($category !== '') {
                $programme['category'] = $category;
                $result['category'] = true;
                $result['changed'] = true;
            }
        } elseif (($mapGenresToKodiGuideGenres || $mapGenresToEpgCategories) && $hasCategory) {
            // Map existing category even if not enriching from TMDB.
            $mapped = $mapGenresToKodiGuideGenres
                ? $this->mapToKodiGuideGenre($programme['category'], $mediaType)
                : $this->mapToEpgCategory($programme['category'], $mediaType);
            if ($mapped !== $programme['category']) {
                $programme['category'] = $mapped;
                $result['category'] = true;
                $result['changed'] = true;
            }
        }

        // Enrich description
        $overview = $tmdbData['overview'] ?? '';

        if ($enrichEpisodeDetails && ($tmdbData['_media_type'] ?? null) === 'tv') {
            $episodeDetails = $this->resolveEpisodeDetails(
                $tmdb,
                $tmdbSeasonCache,
                (int) ($tmdbData['tmdb_id'] ?? 0),
                $seriesSignals['season'],
                $seriesSignals['episode']
            );

            if ($episodeDetails) {
                $episodeOverview = trim((string) ($episodeDetails['overview'] ?? ''));
                if ($episodeOverview !== '') {
                    // Reject when TMDB returned the original-language fallback instead of
                    // the user-configured locale. Plugin still uses the series overview
                    // (already set above as $overview from $tmdbData) as the de-facto desc.
                    $userLangFull = (string) (app(GeneralSettings::class)->tmdb_language ?? 'de-DE');
                    $userIso = strtolower(substr($userLangFull, 0, 2));
                    if ($this->looksLikeLanguage($episodeOverview, $userIso)) {
                        $overview = $episodeOverview;
                    } else {
                        Log::info('[EpgEnricher] Rejected TMDB episode overview: language mismatch', [
                            'expected' => $userIso,
                            'sample' => mb_substr($episodeOverview, 0, 80),
                        ]);
                    }
                }

                $stillUrl = trim((string) ($episodeDetails['still_url'] ?? ''));
                if ($enrichBackdrops && $stillUrl !== '') {
                    // Episode still is added as a landscape screenshot. The series backdrop
                    // remains preferred, but the still can repair an untrusted icon when no
                    // backdrop exists. Attribute-blind clients then still receive a wide image.
                    $existingUrls = array_column($programme['images'] ?? [], 'url');
                    if (! in_array($stillUrl, $existingUrls, true)) {
                        if (! isset($programme['images']) || ! is_array($programme['images'])) {
                            $programme['images'] = [];
                        }
                        $programme['images'][] = [
                            'url' => $stillUrl,
                            'type' => 'screenshot',
                            'width' => 1280,
                            'height' => 720,
                            'orient' => 'L',
                            'size' => 2,
                        ];
                        $result['changed'] = true;
                    }
                    if ($backdropUrl === null
                        && ($overwrite || ! $trustedLandscapeIcon)
                        && ($programme['icon'] ?? null) !== $stillUrl) {
                        $programme['icon'] = $stillUrl;
                        $result['poster'] = true;
                        $result['changed'] = true;
                    }
                }
            }
        }

        if ($enrichDescriptions && $overview !== '' && ($overwrite || ! $hasDesc)) {
            $programme['desc'] = $overview;
            $result['description'] = true;
            $result['changed'] = true;
        }

        // Phase A2/A3: finalize images[] (sort, dedupe, sync primary <icon>).
        if (! empty($programme['images']) && is_array($programme['images'])) {
            $imagesBeforeFinalization = $programme['images'];
            $iconBeforeFinalization = $programme['icon'] ?? null;
            $programme['images'] = $this->prioritizeImages($programme['images']);
            $programme['images'] = $this->dedupeImagesByUrl($programme['images']);
            if ($trustedLandscapeIcon && ! $overwrite) {
                $trustedUrl = (string) ($programme['icon'] ?? '');
                foreach ($programme['images'] as $index => $image) {
                    if (($image['url'] ?? null) !== $trustedUrl) {
                        continue;
                    }
                    if ($index > 0) {
                        array_unshift($programme['images'], array_splice($programme['images'], $index, 1)[0]);
                    }
                    break;
                }
            } else {
                foreach ($programme['images'] as $image) {
                    if ($this->isTrustedLandscapeImage($image)) {
                        $programme['icon'] = $image['url'];
                        break;
                    }
                }
            }
            if ($programme['images'] !== $imagesBeforeFinalization || ($programme['icon'] ?? null) !== $iconBeforeFinalization) {
                $result['changed'] = true;
            }
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

        return PluginActionResult::success('EPG Enricher plugin is healthy.', [
            'plugin_id' => 'epg-enricher',
            'tmdb_configured' => $tmdbConfigured,
            'tmdb_cache_entries' => $cacheEntries,
            'enrichment_state_epgs' => $trackedEpgs,
            'enrichment_state_files' => $trackedFiles,
            'last_enriched_at' => $lastEnrichedAt,
            'top_missed_titles' => $this->topMissedTitles(20),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Resolve the active cache version directory for an EPG.
     * Prefers the newest version that has metadata.json, falls back to legacy versions.
     */
    private function getActiveCacheDir(Epg $epg): ?string
    {
        // Keep this list in sync with EpgCacheService::CACHE_VERSION + PREVIOUS_CACHE_VERSIONS
        $versions = ['v2', 'v1'];
        foreach ($versions as $version) {
            $dir = "epg-cache/{$epg->uuid}/{$version}";
            if (Storage::disk('local')->exists($dir.'/metadata.json')) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Read EPG cache metadata.
     */
    private function readMetadata(Epg $epg): ?array
    {
        $dir = $this->getActiveCacheDir($epg);
        if ($dir === null) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($dir.'/metadata.json'), true);
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
     * Load TMDB season lookup cache from disk.
     *
     * @return array<string, array|null>
     */
    private function loadTmdbSeasonCache(): array
    {
        $path = 'plugin-data/epg-enricher/tmdb-season-cache.json';
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
     * Save TMDB season lookup cache to disk.
     *
     * @param  array<string, array|null>  $cache
     */
    private function saveTmdbSeasonCache(array $cache): void
    {
        Storage::disk('local')->makeDirectory('plugin-data/epg-enricher');
        Storage::disk('local')->put(
            'plugin-data/epg-enricher/tmdb-season-cache.json',
            json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * Load TMDB images endpoint cache from disk.
     * Key = "{media_type}:{tmdb_id}:{language}".
     *
     * @return array<string, array|null>
     */
    private function loadTmdbImagesCache(): array
    {
        $path = storage_path('app/plugin-data/epg-enricher/tmdb-images-cache.json');
        if (! file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_filter($decoded, 'is_array') : [];
    }

    /**
     * Save TMDB images endpoint cache to disk.
     *
     * @param  array<string, array|null>  $cache
     */
    private function saveTmdbImagesCache(array $cache): void
    {
        $dir = storage_path('app/plugin-data/epg-enricher');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir.'/tmdb-images-cache.json';
        $successfulResponses = array_filter($cache, 'is_array');
        @file_put_contents($path, json_encode($successfulResponses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Append a missed (no-TMDB-match) title to the JSONL log for later tuning.
     */
    private function logMissedTitle(string $title, string $baseTitle, ?int $year, ?string $forcedMediaType): void
    {
        $dir = storage_path('app/plugin-data/epg-enricher');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = json_encode([
            'ts' => date('c'),
            'title' => $title,
            'base' => $baseTitle,
            'year' => $year,
            'forced_type' => $forcedMediaType,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir.'/missed-titles.jsonl', $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Aggregate the missed-titles log into a top-N count list.
     *
     * @return array<int, array{title: string, count: int, last_seen: string}>
     */
    private function topMissedTitles(int $limit = 20): array
    {
        $path = storage_path('app/plugin-data/epg-enricher/missed-titles.jsonl');
        if (! file_exists($path)) {
            return [];
        }
        $counts = [];
        $lastSeen = [];
        $handle = @fopen($path, 'r');
        if (! $handle) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);
            if (! is_array($entry) || empty($entry['title'])) {
                continue;
            }
            $key = (string) $entry['title'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $lastSeen[$key] = $entry['ts'] ?? '';
        }
        fclose($handle);
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $title => $count) {
            $out[] = [
                'title' => $title,
                'count' => $count,
                'last_seen' => $lastSeen[$title] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Fetch /movie/{id}/images or /tv/{id}/images from TMDB.
     *
     * @return array{posters: array, backdrops: array, logos: array}|null
     */
    private function fetchTmdbImages(int $tmdbId, string $mediaType, array &$cache): ?array
    {
        $creds = $this->getTmdbCredentials();
        if ($creds === null) {
            return null;
        }

        $cacheKey = "{$mediaType}:{$tmdbId}:".strtolower($creds['language']);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $endpoint = $mediaType === 'movie' ? "movie/{$tmdbId}/images" : "tv/{$tmdbId}/images";
        $shortLang = explode('-', $creds['language'])[0] ?? 'en';

        try {
            $response = Http::timeout(15)->get(
                "https://api.themoviedb.org/3/{$endpoint}",
                [
                    'api_key' => $creds['key'],
                    // null = sprach-neutrale assets (wichtig für logos)
                    'include_image_language' => "{$shortLang},en,null",
                ]
            );

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $result = [
                'posters' => is_array($data['posters'] ?? null) ? $data['posters'] : [],
                'backdrops' => is_array($data['backdrops'] ?? null) ? $data['backdrops'] : [],
                'logos' => is_array($data['logos'] ?? null) ? $data['logos'] : [],
            ];
            $cache[$cacheKey] = $result;

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[EpgEnricher] TMDB images fetch failed', [
                'tmdb_id' => $tmdbId,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Select the best image variants per type from a TMDB images response.
     * Returns programme-ready image entries (url + type + width + height + orient + size).
     *
     * @param  array{posters: array, backdrops: array, logos: array}  $images
     * @return array<int, array{url: string, type: string, width: int, height: int, orient: string, size: int}>
     */
    private function selectImageSet(array $images, string $userLang): array
    {
        $shortLang = explode('-', $userLang)[0] ?? 'en';
        $out = [];

        $rank = function (array $img, array $langPriority): int {
            $iso = $img['iso_639_1'] ?? null;
            foreach ($langPriority as $idx => $code) {
                if ($iso === $code || ($code === null && $iso === null)) {
                    return $idx;
                }
            }

            return count($langPriority);
        };

        $sortBy = function (array $a, array $b, array $langPriority) use ($rank): int {
            $ra = $rank($a, $langPriority);
            $rb = $rank($b, $langPriority);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            // Höhere vote_average zuerst
            return ($b['vote_average'] ?? 0) <=> ($a['vote_average'] ?? 0);
        };

        // Backdrops zuerst (landscape primary, size=1), sprach-neutral bevorzugt
        $backdrops = $images['backdrops'] ?? [];
        $langPrioBack = [null, $shortLang, 'en'];
        usort($backdrops, fn ($a, $b) => $sortBy($a, $b, $langPrioBack));
        foreach (array_slice($backdrops, 0, 2) as $b) {
            if (empty($b['file_path'])) {
                continue;
            }
            $out[] = [
                'url' => 'https://image.tmdb.org/t/p/w1280'.$b['file_path'],
                'type' => 'backdrop',
                'width' => 1280,
                'height' => (int) round(1280 / max($b['aspect_ratio'] ?? 1.778, 0.1)),
                'orient' => 'L',
                'size' => 1,
            ];
        }

        // Posters: user-lang bevorzugt (portrait, size=2)
        $posters = $images['posters'] ?? [];
        $langPrioPoster = [$shortLang, 'en', null];
        usort($posters, fn ($a, $b) => $sortBy($a, $b, $langPrioPoster));
        foreach (array_slice($posters, 0, 2) as $p) {
            if (empty($p['file_path'])) {
                continue;
            }
            $out[] = [
                'url' => 'https://image.tmdb.org/t/p/w500'.$p['file_path'],
                'type' => 'poster',
                'width' => 500,
                'height' => (int) round(500 / max($p['aspect_ratio'] ?? 0.667, 0.1)),
                'orient' => 'P',
                'size' => 2,
            ];
        }

        // Logos: user-lang→en→null (landscape transparent, size=3)
        $logos = $images['logos'] ?? [];
        $langPrioLogo = [$shortLang, 'en', null];
        usort($logos, fn ($a, $b) => $sortBy($a, $b, $langPrioLogo));
        foreach (array_slice($logos, 0, 1) as $l) {
            if (empty($l['file_path'])) {
                continue;
            }
            $out[] = [
                'url' => 'https://image.tmdb.org/t/p/w500'.$l['file_path'],
                'type' => 'logo',
                'width' => 500,
                'height' => (int) round(500 / max($l['aspect_ratio'] ?? 2.5, 0.1)),
                'orient' => 'L',
                'size' => 3,
            ];
        }

        return $out;
    }

    private function hasTrustedLandscapeIcon(array $programme): bool
    {
        $icon = trim((string) ($programme['icon'] ?? ''));
        if ($icon === '' || ! is_array($programme['images'] ?? null)) {
            return false;
        }

        foreach ($programme['images'] as $image) {
            if (($image['url'] ?? null) === $icon && $this->isTrustedLandscapeImage($image)) {
                return true;
            }
        }

        return false;
    }

    private function isTrustedLandscapeImage(array $image): bool
    {
        $type = strtolower(trim((string) ($image['type'] ?? '')));
        $orient = strtoupper(trim((string) ($image['orient'] ?? '')));

        if (empty($image['url'])
            || $orient !== 'L'
            || ! in_array($type, ['backdrop', 'fanart', 'screenshot'], true)) {
            return false;
        }

        $width = $image['width'] ?? null;
        $height = $image['height'] ?? null;
        if (is_numeric($width) && is_numeric($height) && (float) $width > 0 && (float) $height > 0) {
            return (float) $width > (float) $height;
        }

        return true;
    }

    /**
     * Sort $programme['images'][] by orientation+type+width so the first usable
     * landscape image becomes the primary. Attribute-blind clients (Emby,
     * Tvheadend) only read the first <icon>; this guarantees they get a wide
     * image. Attribute-aware clients (Kodi, Jellyfin, Plex) keep using
     * type/orient/width to pick the right variant per view.
     *
     * Type and orientation rank before width, so a large logo or portrait can
     * never outrank a real landscape image.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function prioritizeImages(array $images): array
    {
        if (empty($images)) {
            return $images;
        }

        $score = function (array $img): int {
            $type = strtolower((string) ($img['type'] ?? 'poster'));
            $orient = strtoupper((string) ($img['orient'] ?? 'P'));
            $width = (int) ($img['width'] ?? 0);
            $base = match ($type) {
                'backdrop' => $orient === 'L' ? 500 : 150,
                'fanart' => $orient === 'L' ? 400 : 140,
                'screenshot' => $orient === 'L' ? 300 : 130,
                'poster' => 100,
                'logo' => 0,
                default => $orient === 'L' ? 200 : 50,
            };
            return ($base * 100000) + $width;
        };

        // Stable sort by score desc.
        $indexed = [];
        foreach ($images as $i => $img) {
            $indexed[] = [$i, $score($img), $img];
        }
        usort($indexed, function ($a, $b) {
            if ($a[1] === $b[1]) {
                return $a[0] <=> $b[0];
            }
            return $b[1] <=> $a[1];
        });

        return array_map(fn ($row) => $row[2], $indexed);
    }

    /**
     * Remove duplicate entries from $programme['images'][] by URL.
     * Keeps the first occurrence (which after prioritizeImages is the highest-scored).
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function dedupeImagesByUrl(array $images): array
    {
        $seen = [];
        $out = [];
        foreach ($images as $img) {
            $url = $img['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $img;
        }
        return array_values($out);
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
     * Map genre string(s) + TMDB media type to a canonical EPG category that
     * triggers guide color coding in clients like Emby, Jellyfin, Kodi, Plex.
     *
     * Priority: scan ALL genres for specific categories (News, Sports, Kids)
     * first, then fall back to Movie/Series based on the TMDB media type.
     * This prevents e.g. M*A*S*H (Comedy, War & Politics, Drama) from being
     * labelled "News" just because "War & Politics" appears in its genres.
     *
     * @param  string  $genres  Comma-separated genre string
     * @param  string|null  $mediaType  'tv' or 'movie' from TMDB lookup
     */
    private function mapToEpgCategory(string $genres, ?string $mediaType): string
    {
        $genreList = array_map('trim', explode(',', $genres));
        $genreKeys = array_map('mb_strtolower', $genreList);

        // Collect all mapped categories across every genre
        $mapped = [];
        foreach ($genreKeys as $key) {
            if (isset(self::EPG_CATEGORY_MAP[$key])) {
                $mapped[self::EPG_CATEGORY_MAP[$key]] = true;
            }
        }

        // Priority 1: Sports, always unambiguous
        if (isset($mapped['Sports'])) {
            return 'Sports';
        }

        // Priority 2: Kids, check explicit "Kids" mapping OR the kids-adjacent
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

        // Priority 3: News, but only when it's actually news/journalism content.
        // TV series that merely touch political themes (e.g. M*A*S*H with
        // "War & Politics") should NOT be tagged as News.
        if (isset($mapped['News']) && $mediaType !== 'tv') {
            return 'News';
        }

        // Priority 4: Documentary, before the generic media type fallback
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

        // Priority 6: no media type available; use the first mapped category
        foreach (['Movie', 'Series', 'Music', 'Education', 'News'] as $cat) {
            if (isset($mapped[$cat])) {
                return $cat;
            }
        }

        // Last resort: return the first genre as-is
        return $genreList[0] ?? $genres;
    }

    /**
     * Map TMDB or provider genres to detailed Kodi guide color labels.
     *
     * @param  string  $genres  Comma-separated genre string
     * @param  string|null  $mediaType  Reserved for future client-specific tuning
     */
    private function mapToKodiGuideGenre(string $genres, ?string $mediaType): string
    {
        $genreList = array_values(array_filter(array_map('trim', explode(',', $genres)), fn ($genre) => $genre !== ''));
        $genreKeys = array_map('mb_strtolower', $genreList);
        $genreSet = array_fill_keys($genreKeys, true);

        $comboMatches = [
            [['comedy', 'drama'], 'Comedy-Drama'],
            [['romance', 'comedy'], 'Romance-Comedy'],
            [['comedy', 'romance'], 'Comedy-Romance'],
            [['horror', 'suspense'], 'Horror Suspense'],
            [['action', 'adventure'], 'Action and Adventure'],
            [['crime', 'drama'], 'Crime Drama'],
            [['fitness', 'health'], 'Fitness Health'],
            [['home', 'garden'], 'Home and Garden'],
        ];
        foreach ($comboMatches as [$keys, $label]) {
            if (count(array_intersect_key($genreSet, array_flip($keys))) === count($keys)) {
                return $label;
            }
        }

        foreach ($genreKeys as $key) {
            if (isset(self::KODI_GUIDE_GENRE_MAP[$key])) {
                return self::KODI_GUIDE_GENRE_MAP[$key];
            }
        }

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
    private function extractBaseTitle(string $title): array
    {
        $title = trim($title);

        // Extract year if present (e.g. "Inception (2010)", "Avatar - 2009").
        // Use the LAST occurrence so titles containing a year token early
        // (rare but possible) don't trick us.
        $year = null;
        if (preg_match_all('/\b(19\d{2}|20\d{2})\b/', $title, $yearMatches)) {
            $year = (int) end($yearMatches[1]);
        }

        // Strip trailing episode markers: "(12)", "S01E03", "Folge 5", "Teil 2", etc.
        $cleaned = preg_replace('/\s*\(\d{1,4}\)\s*$/', '', $title);
        $cleaned = preg_replace('/\s*S\d{1,2}E\d{1,2}\s*$/i', '', $cleaned);
        $cleaned = preg_replace('/\s*[-\x{2013}\x{2014}]\s*(?:Folge|Episode|Ep\.?|Teil|Part)\s*\d+\s*$/iu', '', $cleaned);
        $cleaned = preg_replace('/\s*(?:Folge|Episode|Ep\.?|Teil|Part)\s*\d+\s*$/i', '', $cleaned);
        $cleaned = rtrim(trim($cleaned), "-\u{2013}\u{2014} ");

        // Strip trailing year markers like "(2010)" or " - 2009"
        $cleaned = preg_replace('/\s*\((?:19\d{2}|20\d{2})\)\s*$/', '', $cleaned);
        $cleaned = preg_replace('/\s*[-\x{2013}\x{2014}]\s*(?:19\d{2}|20\d{2})\s*$/u', '', $cleaned);
        $cleaned = rtrim(trim($cleaned), "-\u{2013}\u{2014} ");

        // Split at spaced hyphen, en dash, or em dash (common EPG episode separators)
        if (preg_match('/^(.{2,}?)\s+[-\x{2013}\x{2014}]\s+(.+)$/u', $cleaned, $m)) {
            return ['title' => trim($m[1]), 'year' => $year];
        }

        // Split at ": " when right part is long (episode subtitle, not abbreviation like "LA")
        if (preg_match('/^(.{2,}?):\s+(.{8,})$/u', $cleaned, $m)) {
            return ['title' => trim($m[1]), 'year' => $year];
        }

        return ['title' => $cleaned, 'year' => $year];
    }

    /**
     * Search TMDB for a title with result validation.
     *
     * Scores TV and movie candidates globally unless explicit episode evidence
     * forces TV. Identity confidence is independent from available artwork.
     *
     * @return array|null TMDB data with '_media_type' set, or null if no good match
     */
    private function searchTmdbWithValidation(
        TmdbService $tmdb,
        string $searchTitle,
        ?string $forceMediaType = null,
        ?int $year = null,
        string $description = '',
    ): ?array
    {
        $searchNorm = mb_strtolower(trim($searchTitle));
        $candidates = [];

        if ($forceMediaType !== 'movie') {
            $tvResult = $tmdb->searchTvSeries($searchTitle, $year);
            if ($tvResult && ($tvResult['tmdb_id'] ?? null)) {
                $details = $tmdb->getTvSeriesDetails((int) $tvResult['tmdb_id']);
                if ($details) {
                    $tvResult = array_merge($tvResult, $details);
                }
                $alternatives = $tmdb->getTvAlternativeTitles((int) $tvResult['tmdb_id']);
                $candidates[] = $this->scoreTmdbCandidate($tvResult, 'tv', $searchNorm, $year, $description, $alternatives);
            }
        }

        if ($forceMediaType !== 'tv') {
            $movieResult = $tmdb->searchMovie($searchTitle, $year, tryFallback: true);
            if ($movieResult && ($movieResult['tmdb_id'] ?? null)) {
                $details = $tmdb->getMovieDetails((int) $movieResult['tmdb_id']);
                if ($details) {
                    $movieResult = array_merge($movieResult, $details);
                }
                $alternatives = $tmdb->getMovieAlternativeTitles((int) $movieResult['tmdb_id']);
                $candidates[] = $this->scoreTmdbCandidate($movieResult, 'movie', $searchNorm, $year, $description, $alternatives);
            }
        }

        usort($candidates, fn (array $a, array $b): int => $b['_identity_score'] <=> $a['_identity_score']);
        $best = $candidates[0] ?? null;
        if ($best === null || $best['_identity_score'] < 76.0 || ! $best['_identity_valid']) {
            return null;
        }
        if (isset($candidates[1]) && ($best['_identity_score'] - $candidates[1]['_identity_score']) < 8.0) {
            return null;
        }

        unset($best['_identity_valid']);

        return $best;
    }

    private function scoreTmdbCandidate(
        array $candidate,
        string $mediaType,
        string $searchNorm,
        ?int $year,
        string $description,
        array $alternativeTitles,
    ): array {
        $titleFields = $mediaType === 'tv'
            ? [$candidate['name'] ?? '', $candidate['original_name'] ?? '']
            : [$candidate['title'] ?? '', $candidate['original_title'] ?? ''];
        $bestTitleScore = 0.0;
        $bestTitleSource = 'primary';
        foreach ($titleFields as $title) {
            $bestTitleScore = max($bestTitleScore, $this->titleMatchScore($searchNorm, (string) $title));
        }
        foreach ($alternativeTitles as $alternative) {
            $alternativeTitle = (string) ($alternative['title'] ?? $alternative['name'] ?? '');
            $alternativeScore = $this->titleMatchScore($searchNorm, $alternativeTitle);
            if ($alternativeScore > $bestTitleScore) {
                $bestTitleScore = $alternativeScore;
                $bestTitleSource = 'alternative';
            }
        }

        $candidateDate = (string) ($candidate[$mediaType === 'tv' ? 'first_air_date' : 'release_date'] ?? '');
        $candidateYear = preg_match('/^(19\d{2}|20\d{2})/', $candidateDate, $matches) ? (int) $matches[1] : null;
        $yearScore = 0;
        $yearExact = false;
        if ($year !== null && $candidateYear !== null) {
            $difference = abs($year - $candidateYear);
            $yearExact = $difference === 0;
            $yearScore = match (true) {
                $difference === 0 => 12,
                $difference === 1 => 4,
                $difference >= 3 => -12,
                default => 0,
            };
        }

        $descriptionScore = $this->descriptionEvidenceScore($description, $candidate);
        $identityScore = ($bestTitleScore * 80) + $yearScore + $descriptionScore;
        $alternativeIsStrong = $bestTitleSource !== 'alternative'
            || ($bestTitleScore >= 0.9 && $yearExact && $descriptionScore >= 8);

        $candidate['_media_type'] = $mediaType;
        $candidate['_identity_score'] = round($identityScore, 3);
        $candidate['_identity_valid'] = $alternativeIsStrong;

        return $candidate;
    }

    private function descriptionEvidenceScore(string $description, array $candidate): int
    {
        $descriptionNorm = $this->normalizeIdentityText($description);
        if ($descriptionNorm === '') {
            return 0;
        }

        $people = [];
        foreach (['cast', 'director'] as $field) {
            $value = $candidate[$field] ?? [];
            $entries = is_array($value) ? $value : explode(',', (string) $value);
            foreach ($entries as $entry) {
                $name = $this->normalizeIdentityText((string) $entry);
                if ($name !== '') {
                    $people[$name] = true;
                }
            }
        }

        $personScore = 0;
        foreach (array_keys($people) as $person) {
            if (str_contains(' '.$descriptionNorm.' ', ' '.$person.' ')) {
                $personScore += 4;
            }
        }

        $overviewTokens = array_unique(array_filter(
            preg_split('/\s+/', $this->normalizeIdentityText((string) ($candidate['overview'] ?? '')), -1, PREG_SPLIT_NO_EMPTY),
            fn (string $token): bool => mb_strlen($token) >= 5
        ));
        $descriptionTokens = array_flip(preg_split('/\s+/', $descriptionNorm, -1, PREG_SPLIT_NO_EMPTY));
        $overlap = 0;
        foreach ($overviewTokens as $token) {
            if (isset($descriptionTokens[$token])) {
                $overlap++;
            }
        }
        $overviewScore = $overlap >= 2 ? min(6, $overlap * 2) : 0;

        return min(12, $personScore + $overviewScore);
    }

    private function normalizeIdentityText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized);

        return trim(preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * Compute a similarity score (0.0-1.0) between an EPG search title and a TMDB result title.
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
            // Very short substring in very long title; fall through to token matching
        }

        // Bidirectional token overlap scoring.
        // Forward: how many search tokens appear in the TMDB result?
        // Reverse: how many TMDB tokens appear in the search title?
        // Use the minimum; both sides must have reasonable coverage.
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
     * Detect an EPG category from keywords found in the programme title.
     * Used as a fallback when TMDB lookup fails (live sports, news, etc.).
     *
     * @return string|null The matched category, or null if no keywords match
     */
    private function detectCategoryFromTitle(string $title): ?string
    {
        $titleLower = mb_strtolower($title);

        foreach (self::TITLE_KEYWORD_CATEGORIES as $category => $keywords) {
            foreach ($keywords as $keyword) {
                // Use word boundary matching to avoid false positives
                // e.g. "art" should not match inside "Karting"
                $pattern = '/(?:^|[\s\-\/\|:.,;!?\(\[])'.preg_quote($keyword, '/').'(?:$|[\s\-\/\|:.,;!?\)\]])/u';
                if (preg_match($pattern, $titleLower)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Detect whether a programme likely represents an episodic TV series.
     *
     * @return array{is_series_episode: bool, season: int|null, episode: int|null, confidence: string}
     */
    private function detectSeriesSignals(array $programme): array
    {
        $subtitle = trim((string) ($programme['subtitle'] ?? ''));
        $episodeNum = trim((string) ($programme['episode_num'] ?? ''));

        [$season, $episode] = $this->parseSeasonEpisode($episodeNum);
        $seFromText = false;
        if ($season === null && $episode === null) {
            $haystack = $subtitle.' '.trim((string) ($programme['desc'] ?? ''));
            if (trim($haystack) !== '') {
                [$season, $episode] = $this->parseSeasonEpisodeFromText($haystack);
                if ($season !== null || $episode !== null) {
                    $seFromText = true;
                }
            }
        }

        $hasSubtitle = $subtitle !== '';
        $hasParsedEpisode = $season !== null || $episode !== null;
        $hasEpisodicProviderId = (bool) preg_match('/^(EP|SH|MV|SP)\d+/i', $episodeNum);

        $isSeriesEpisode = $hasSubtitle || $hasParsedEpisode || $hasEpisodicProviderId;

        $confidence = 'none';
        if ($hasSubtitle && ($hasParsedEpisode || $hasEpisodicProviderId) && ! $seFromText) {
            $confidence = 'high';
        } elseif ($isSeriesEpisode) {
            $confidence = 'medium';
        }

        return [
            'is_series_episode' => $isSeriesEpisode,
            'season' => $season,
            'episode' => $episode,
            'confidence' => $confidence,
        ];
    }

    /**
     * Parse season/episode numbers from common XMLTV episode formats.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseSeasonEpisode(string $episodeNum): array
    {
        $value = mb_strtolower(trim($episodeNum));
        if ($value === '') {
            return [null, null];
        }

        // xmltv_ns format: season.episode.part/total (zero-based season/episode)
        if (preg_match('/^(\d+)\.(\d+)(?:\.\d+)?(?:\/\d+)?$/', $value, $m)) {
            return [(int) $m[1] + 1, (int) $m[2] + 1];
        }

        // On-screen formats: S01E02, 1x02
        if (preg_match('/s(\d{1,2})\s*e(\d{1,3})/i', $value, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d{1,2})x(\d{1,3})/', $value, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        // Localized wording: Staffel 3 Folge 12 / Folge 12
        if (preg_match('/staffel\s*(\d{1,2}).{0,12}(?:folge|episode|ep\.?|teil)\s*(\d{1,3})/u', $value, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(?:folge|episode|ep\.?|teil)\s*(\d{1,3})/u', $value, $m)) {
            return [null, (int) $m[1]];
        }

        return [null, null];
    }

    /**
     * Parse season/episode from arbitrary text (subtitle/desc) covering DE/EN/ES/FR markers.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function parseSeasonEpisodeFromText(string $text): array
    {
        $value = mb_strtolower(trim($text));
        if ($value === '') {
            return [null, null];
        }

        $patterns = [
            '/\bs(\d{1,2})\s?e(\d{1,3})\b/i',
            '/\b(\d{1,2})x(\d{1,3})\b/',
            '/\bstaffel\s*(\d{1,2})[\s,.\-]+(?:folge|episode|ep\.?|teil)\s*(\d{1,3})/iu',
            '/\btemporada\s*(\d{1,2})[\s,.\-]+(?:cap[ií]tulo|episodio|ep\.?)\s*(\d{1,3})/iu',
            '/\bseason\s*(\d{1,2})[\s,.\-]+episode\s*(\d{1,3})/i',
            '/\bsaison\s*(\d{1,2})[\s,.\-]+(?:[ée]pisode|ep\.?)\s*(\d{1,3})/iu',
            '/\bt(\d{1,2})\s*[\.\-x]\s*e?(\d{1,3})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $m)) {
                return [(int) $m[1], (int) $m[2]];
            }
        }

        if (preg_match('/(?:folge|episode|ep\.?|teil|cap[ií]tulo|episodio)\s*(\d{1,3})/iu', $value, $m)) {
            return [null, (int) $m[1]];
        }

        return [null, null];
    }

    /**
     * Cheap heuristic: does $text plausibly match the requested language?
     * Used to reject TMDB overview fallbacks (e.g. Spanish text returned for de-DE).
     * Returns true on inconclusive (short text, no markers) so we do not over-reject.
     */
    private function looksLikeLanguage(string $text, string $iso639_1): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 30) {
            return true; // too short to judge, accept
        }
        $lower = mb_strtolower($text);
        $iso = strtolower($iso639_1);

        // Marker characters / common stopwords per language (cheap, not exhaustive).
        $markers = [
            'de' => ['ä','ö','ü','ß',' der ',' die ',' das ',' und ',' ist ',' nicht ',' eine ',' einen ',' nach ',' wird '],
            'en' => [' the ',' and ',' is ',' of ',' to ',' with ',' from ',' that ',' this ',' when ',' which '],
            'es' => ['ñ','¿','¡',' el ',' la ',' los ',' las ',' que ',' una ',' por ',' con ',' para ',' del ',' está '],
            'fr' => [' le ',' la ',' les ',' une ',' des ',' que ',' qui ',' avec ',' pour ',' dans ',' est ',' c\'est '],
            'it' => [' il ',' la ',' che ',' una ',' con ',' per ',' del ',' degli ',' nella ',' sono '],
            'pt' => ['ã',' o ',' a ',' os ',' as ',' que ',' uma ',' com ',' para ',' está ',' não '],
        ];

        $countMarkers = function (string $haystack, array $list): int {
            $n = 0;
            foreach ($list as $m) {
                if (str_contains($haystack, $m)) {
                    $n++;
                }
            }
            return $n;
        };

        $expected = $markers[$iso] ?? null;
        if ($expected === null) {
            return true; // unknown language code, accept
        }

        $expectedHits = $countMarkers($lower, $expected);

        // Score every other supported language and keep the max.
        $maxOther = 0;
        foreach ($markers as $code => $list) {
            if ($code === $iso) {
                continue;
            }
            $hits = $countMarkers($lower, $list);
            if ($hits > $maxOther) {
                $maxOther = $hits;
            }
        }

        // Reject only when another language is strongly dominant.
        // Threshold: other has >=3 hits AND at least 2 more than expected.
        if ($maxOther >= 3 && $maxOther >= $expectedHits + 2) {
            return false;
        }
        return true;
    }

    /**
     * Detect known series-franchise keywords in programme titles.
     */
    private function hasEpisodicTitleKeyword(string $title): bool
    {
        $titleLower = mb_strtolower($title);

        foreach (self::EPISODIC_TITLE_KEYWORDS as $keyword) {
            if (str_contains($titleLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve episode details from TMDB by season/episode, using cached season payloads.
     *
     * @param  array<string, array|null>  $seasonCache
     * @return array<string, mixed>|null
     */
    private function resolveEpisodeDetails(
        TmdbService $tmdb,
        array &$seasonCache,
        int $tmdbId,
        ?int $season,
        ?int $episode,
    ): ?array {
        if ($tmdbId <= 0 || $season === null || $episode === null || $season <= 0 || $episode <= 0) {
            return null;
        }

        $cacheKey = "{$tmdbId}:{$season}";
        if (array_key_exists($cacheKey, $seasonCache)) {
            $seasonData = $seasonCache[$cacheKey];
        } else {
            $seasonData = $tmdb->getSeasonDetails($tmdbId, $season);
            $seasonCache[$cacheKey] = $seasonData;
        }

        if (! is_array($seasonData)) {
            return null;
        }

        foreach (($seasonData['episodes'] ?? []) as $episodeData) {
            if ((int) ($episodeData['episode_number'] ?? 0) !== $episode) {
                continue;
            }

            $stillPath = trim((string) ($episodeData['still_path'] ?? ''));
            if ($stillPath !== '' && ! str_starts_with($stillPath, 'http')) {
                $stillPath = "https://image.tmdb.org/t/p/original{$stillPath}";
            }

            $episodeData['still_url'] = $stillPath;

            return $episodeData;
        }

        return null;
    }

    /**
     * Resolve TMDB api credentials from GeneralSettings.
     * Returns null if not configured (caller should skip image fetch silently).
     *
     * @return array{key: string, language: string}|null
     */
    private function getTmdbCredentials(): ?array
    {
        try {
            $settings = app(GeneralSettings::class);
            $key = trim((string) ($settings->tmdb_api_key ?? ''));
            if ($key === '') {
                return null;
            }
            $language = $settings->tmdb_language ?? 'de-DE';

            return ['key' => $key, 'language' => $language];
        } catch (\Throwable $e) {
            Log::warning('[EpgEnricher] Failed to resolve TMDB credentials', ['error' => $e->getMessage()]);

            return null;
        }
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
            'logic_version' => self::ENRICHMENT_LOGIC_VERSION,
            'enrich_from_tmdb' => $settings['enrich_from_tmdb'] ?? true,
            'overwrite_existing' => $settings['overwrite_existing'] ?? false,
            'enrich_categories' => $settings['enrich_categories'] ?? true,
            'enrich_descriptions' => $settings['enrich_descriptions'] ?? true,
            'enrich_posters' => $settings['enrich_posters'] ?? true,
            'enrich_backdrops' => $settings['enrich_backdrops'] ?? true,
            'map_genres_to_epg_categories' => $settings['map_genres_to_epg_categories']
                ?? $settings['map_emby_genres']
                ?? false,
            'map_genres_to_kodi_guide_genres' => $settings['map_genres_to_kodi_guide_genres'] ?? false,
            'keyword_category_detection' => $settings['keyword_category_detection'] ?? true,
            'enrich_episode_details' => $settings['enrich_episode_details'] ?? true,
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
