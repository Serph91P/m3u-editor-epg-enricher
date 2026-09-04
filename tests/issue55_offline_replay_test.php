<?php

namespace {
    function storage_path(string $path = ''): string { return '/dev/null'; }
    function app(string $class): object { return $GLOBALS['issue55Settings']; }
}

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}
    interface PluginSelectOptionsProviderInterface { public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array; }
}

namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}
    class PluginActionResult { public static function success(string $summary, array $data = []): self { return new self(); } public static function failure(string $summary, array $data = []): self { return new self(); } public static function cancelled(string $summary, array $data = []): self { return new self(); } }
    class PluginExecutionContext {}
}

namespace App\Settings {
    class GeneralSettings { public string $tmdb_api_key = ''; public string $tmdb_language = 'en-US'; }
}

namespace Illuminate\Support\Facades {
    class Log { public static function warning(string $message, array $context = []): void {} public static function info(string $message, array $context = []): void {} }
}

namespace App\Services {
    class TmdbService {}

    class ReplayTmdbService extends TmdbService
    {
        public int $tvCandidateSearches = 0;
        public int $movieCandidateSearches = 0;
        public int $seasonRequests = 0;

        public function __construct(private array $tvCandidates, private array $movieCandidates, private array $tvDetails, private array $movieDetails, private array $seasons = []) {}

        public function searchTvSeriesCandidates(string $name, ?int $year = null, int $limit = 5): array { $this->tvCandidateSearches++; return $this->tvCandidates; }
        public function searchMovieCandidates(string $name, ?int $year = null, int $limit = 5): array { $this->movieCandidateSearches++; return $this->movieCandidates; }
        public function getTvSeriesDetails(int $tmdbId): ?array { return $this->tvDetails[$tmdbId] ?? null; }
        public function getMovieDetails(int $tmdbId): ?array { return $this->movieDetails[$tmdbId] ?? null; }
        public function getSeasonDetails(int $tmdbId, int $season): ?array { $this->seasonRequests++; return $this->seasons[$tmdbId.':'.$season] ?? null; }
    }
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Services\ReplayTmdbService;
    use App\Settings\GeneralSettings;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionClass;

    function replayAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    function tvDetails(int $id, string $name, string $backdrop): array
    {
        return [
            'tmdb_id' => $id, 'tvdb_id' => null, 'imdb_id' => null, 'name' => $name, 'original_name' => $name,
            'overview' => null, 'poster_url' => null, 'backdrop_url' => $backdrop, 'first_air_date' => null,
            'genres' => '', 'vote_average' => null, 'vote_count' => null, 'status' => null,
            'number_of_seasons' => null, 'number_of_episodes' => null, 'cast' => null, 'director' => null, 'youtube_trailer' => null,
        ];
    }

    function movieDetails(int $id, string $title, string $backdrop): array
    {
        return [
            'tmdb_id' => $id, 'imdb_id' => null, 'title' => $title, 'original_title' => $title,
            'overview' => null, 'poster_url' => null, 'backdrop_url' => $backdrop, 'release_date' => null,
            'genres' => '', 'vote_average' => null, 'vote_count' => null, 'runtime' => null, 'status' => null,
            'cast' => [], 'director' => [], 'youtube_trailer' => null,
        ];
    }

    function replayOption(string $name): ?string
    {
        foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
            if (str_starts_with($argument, '--'.$name.'=')) {
                return substr($argument, strlen($name) + 3);
            }
        }

        return null;
    }

    function replayPreviewText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === ''
            || str_contains($value, '://')
            || preg_match('/\b[a-z0-9-]+(?:\.[a-z0-9-]+)+\b/i', $value) === 1) {
            return null;
        }

        return mb_substr($value, 0, 160);
    }

    function replayOpaqueArtReference(string $kind, mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return $kind.'_'.substr(hash('sha256', $url), 0, 16);
    }

    function replayManifestCase(string $id, array $programme): array
    {
        $decision = is_array($programme['tmdb_decision'] ?? null) ? $programme['tmdb_decision'] : [];
        $roles = array_values(array_unique(array_filter(array_map(
            static fn (mixed $image): ?string => is_array($image) && is_string($image['type'] ?? null) ? $image['type'] : null,
            $programme['images'] ?? [],
        ))));
        $existingArt = null;
        $tmdbArt = [];
        foreach ($programme['images'] ?? [] as $image) {
            if (! is_array($image) || ! is_string($image['url'] ?? null)) {
                continue;
            }
            if (($image['source'] ?? null) === 'tmdb') {
                $tmdbArt[] = replayOpaqueArtReference('tmdb_art', $image['url']);
            } elseif ($existingArt === null) {
                $existingArt = replayOpaqueArtReference('provider_art', $image['url']);
            }
        }
        $evidence = array_filter([
            'title' => replayPreviewText($programme['title'] ?? null),
            'subtitle' => replayPreviewText($programme['sub_title'] ?? $programme['subtitle'] ?? null),
            'year' => is_int($decision['year'] ?? null) ? $decision['year'] : null,
            'category' => replayPreviewText($programme['category'] ?? null),
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'id' => $id,
            'evidence' => $evidence,
            'applicability' => [
                'class' => $decision['class'] ?? 'ambiguous_identity',
                'result' => $decision['result'] ?? 'unmatched',
                'reason' => $decision['reason'] ?? 'no_valid_candidate',
            ],
            'selected_candidate_fingerprint' => $decision['selected_candidate_fingerprint'] ?? null,
            'runner_up_candidate_fingerprint' => $decision['runner_up_candidate_fingerprint'] ?? null,
            'score' => $decision['score'] ?? null,
            'margin' => $decision['margin'] ?? null,
            'expected_xmltv' => [
                'first_primary_role' => $programme['images'][0]['type'] ?? null,
                'final_primary_role' => $programme['images'][array_key_last($programme['images'] ?? [])]['type'] ?? null,
                'typed_roles' => $roles,
            ],
            'artwork_references' => array_filter([
                'existing_provider_art' => $existingArt,
                'proposed_tmdb_art' => array_values(array_unique(array_filter($tmdbArt))),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    function replayHtmlEscape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function replayGalleryHtml(array $manifest): string
    {
        $cards = '';
        foreach ($manifest['cases'] as $case) {
            $evidence = $case['evidence'] ?? [];
            $applicability = $case['applicability'] ?? [];
            $artwork = $case['artwork_references'] ?? [];
            $roles = implode(', ', $case['expected_xmltv']['typed_roles'] ?? []);
            $cards .= "<article><h2>".replayHtmlEscape($case['id'])."</h2><dl>"
                ."<dt>Title</dt><dd>".replayHtmlEscape($evidence['title'] ?? '')."</dd>"
                ."<dt>Subtitle</dt><dd>".replayHtmlEscape($evidence['subtitle'] ?? '')."</dd>"
                ."<dt>Year</dt><dd>".replayHtmlEscape($evidence['year'] ?? '')."</dd>"
                ."<dt>Category</dt><dd>".replayHtmlEscape($evidence['category'] ?? '')."</dd>"
                ."<dt>Decision</dt><dd>".replayHtmlEscape($applicability['class'] ?? '')." / ".replayHtmlEscape($applicability['result'] ?? '')." / ".replayHtmlEscape($applicability['reason'] ?? '')."</dd>"
                ."<dt>Candidate evidence</dt><dd>".replayHtmlEscape($case['selected_candidate_fingerprint'] ?? '')." / ".replayHtmlEscape($case['runner_up_candidate_fingerprint'] ?? '')."</dd>"
                ."<dt>Score / margin</dt><dd>".replayHtmlEscape($case['score'] ?? '')." / ".replayHtmlEscape($case['margin'] ?? '')."</dd>"
                ."<dt>XMLTV roles</dt><dd>".replayHtmlEscape($case['expected_xmltv']['first_primary_role'] ?? '')." / ".replayHtmlEscape($case['expected_xmltv']['final_primary_role'] ?? '')." / ".replayHtmlEscape($roles)."</dd>"
                ."<dt>Artwork references</dt><dd>".replayHtmlEscape($artwork['existing_provider_art'] ?? '')." / ".replayHtmlEscape(implode(', ', $artwork['proposed_tmdb_art'] ?? []))."</dd>"
                ."</dl></article>";
        }

        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Issue 55 Offline Replay Preview</title><style>body{background:#f4f1eb;color:#252422;font:16px/1.45 system-ui,sans-serif;margin:2rem}main{max-width:1100px;margin:auto}section{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}article{background:#fff;border-top:4px solid #62755b;padding:1rem;box-shadow:0 1px 3px #0002}h1,h2{margin-top:0}h2{font-size:1rem}dl{margin:0}dt{color:#65705e;font-size:.75rem;font-weight:700;text-transform:uppercase}dd{margin:0 0 .75rem;overflow-wrap:anywhere}</style></head><body><main><h1>Issue 55 Offline Replay Preview</h1><p>Deterministic pre-Canary evidence only. No network, cache, database, or production writes are performed.</p><section>".$cards."</section></main></body></html>\n";
    }

    function writeReplayPreview(string $directory, array $manifest): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create replay preview directory.');
        }
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($directory.'/manifest.json', $manifestJson) === false
            || file_put_contents($directory.'/gallery.html', replayGalleryHtml($manifest)) === false) {
            throw new \RuntimeException('Unable to write replay preview artifact.');
        }
    }

    $GLOBALS['issue55Settings'] = new GeneralSettings();
    $plugin = new Plugin();
    $method = (new ReflectionClass($plugin))->getMethod('enrichProgrammeFromTmdb');
    $method->setAccessible(true);
    $run = static function (array $programme, ReplayTmdbService $tmdb, bool $episodes = false) use ($plugin, $method): array {
        $cache = [];
        $seasonCache = [];
        $imagesCache = [];
        $method->invokeArgs($plugin, [&$programme, $tmdb, &$cache, false, true, true, true, true, false, false, false, $episodes, &$seasonCache, &$imagesCache, []]);

        return [$programme, $tmdb];
    };
    $candidate = ['tmdb_id' => 901, 'name' => 'Cafe Racer Night Shift', 'original_name' => 'Cafe Racer Night Shift', 'first_air_date' => '2024-01-01', 'overview' => ''];
    [$catalogue, $catalogueTmdb] = $run(['title' => "Caf\u{00e9}-Racer: Night Shift"], new ReplayTmdbService([$candidate], [], [901 => tvDetails(901, 'Cafe Racer Night Shift', 'https://image.tmdb.org/t/p/original/replay.jpg')], []));
    [$ambiguous, $ambiguousTmdb] = $run(['title' => 'Shared Signal'], new ReplayTmdbService([
        ['tmdb_id' => 902, 'name' => 'Shared Signal', 'original_name' => 'Shared Signal', 'first_air_date' => '2024-01-01', 'overview' => ''],
    ], [
        ['tmdb_id' => 903, 'title' => 'Shared Signal', 'original_title' => 'Shared Signal', 'release_date' => '2024-01-01', 'overview' => ''],
    ], [902 => tvDetails(902, 'Shared Signal', 'https://image.tmdb.org/t/p/original/tv.jpg')], [903 => movieDetails(903, 'Shared Signal', 'https://image.tmdb.org/t/p/original/movie.jpg')]));
    [$provider, $providerTmdb] = $run([
        'title' => 'Sky Sport News Live', 'category' => 'Sports', 'icon' => 'https://provider.invalid/primary.jpg',
        'images' => [['url' => 'https://provider.invalid/primary.jpg', 'type' => 'fanart', 'orient' => 'L', 'width' => 1920, 'height' => 1080, 'scope' => 'programme']],
        'desc' => 'private fixture description',
    ], new ReplayTmdbService([], [], [], []));
    [$dazn, $daznTmdb] = $run(['title' => 'DAZN Live', 'category' => 'Sports'], new ReplayTmdbService([], [], [], []));
    [$escaped, $escapedTmdb] = $run(['title' => 'News <script>', 'category' => 'News'], new ReplayTmdbService([], [], [], []));
    $episodeCandidate = ['tmdb_id' => 904, 'name' => 'Serial Beacon', 'original_name' => 'Serial Beacon', 'first_air_date' => '2024-01-01', 'overview' => ''];
    [$episodeValid, $episodeValidTmdb] = $run(['title' => 'Serial Beacon', 'episode_num' => '0.0'], new ReplayTmdbService([$episodeCandidate], [], [904 => tvDetails(904, 'Serial Beacon', 'https://image.tmdb.org/t/p/original/serial.jpg')], [], [
        '904:1' => ['episodes' => [['episode_number' => 1, 'overview' => '', 'still_path' => '/serial-s01e01.jpg']],],
    ]), true);
    [$episodeWrong, $episodeWrongTmdb] = $run(['title' => 'Serial Beacon', 'episode_num' => 'not-an-episode'], new ReplayTmdbService([$episodeCandidate], [], [904 => tvDetails(904, 'Serial Beacon', 'https://image.tmdb.org/t/p/original/serial.jpg')], []), true);

    replayAssert(($catalogue['tmdb_decision']['result'] ?? null) === 'selected' && $catalogueTmdb->tvCandidateSearches === 1, 'Catalogue normalization replay mismatch.');
    replayAssert(($ambiguous['tmdb_decision']['class'] ?? null) === 'ambiguous_identity' && $ambiguousTmdb->tvCandidateSearches === 1 && $ambiguousTmdb->movieCandidateSearches === 1, 'Global ambiguity replay mismatch.');
    replayAssert(($provider['tmdb_decision']['class'] ?? null) === 'provider_art_preserved' && $providerTmdb->tvCandidateSearches + $providerTmdb->movieCandidateSearches === 0, 'Provider preservation replay mismatch.');
    replayAssert(($dazn['tmdb_decision']['class'] ?? null) === 'sports_or_live_fallback' && $daznTmdb->tvCandidateSearches + $daznTmdb->movieCandidateSearches === 0, 'Live applicability replay mismatch.');
    replayAssert(($escaped['tmdb_decision']['class'] ?? null) === 'sports_or_live_fallback' && $escapedTmdb->tvCandidateSearches + $escapedTmdb->movieCandidateSearches === 0, 'Escaped preview fixture replay mismatch.');
    replayAssert(($episodeValid['images'][1]['type'] ?? null) === 'screenshot' && $episodeValidTmdb->seasonRequests === 1, 'Valid episode replay mismatch.');
    replayAssert(! in_array('screenshot', array_column($episodeWrong['images'] ?? [], 'type'), true) && $episodeWrongTmdb->seasonRequests === 0, 'Wrong episode replay mismatch.');

    $output = [];
    $replayProgrammes = ['catalogue' => $catalogue, 'ambiguous' => $ambiguous, 'provider' => $provider, 'live_fallback' => $dazn, 'episode_valid' => $episodeValid, 'episode_wrong' => $episodeWrong, 'escaped_news' => $escaped];
    foreach ($replayProgrammes as $name => $programme) {
        $decision = $programme['tmdb_decision'];
        $output[$name] = array_filter([
            'class' => $decision['class'], 'result' => $decision['result'], 'reason' => $decision['reason'],
            'score' => $decision['score'], 'margin' => $decision['margin'],
            'primary_role' => $programme['images'][0]['type'] ?? null,
            'secondary_role' => $programme['images'][1]['type'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
    $serialized = json_encode($output, JSON_THROW_ON_ERROR);
    replayAssert(! str_contains($serialized, 'provider.invalid') && ! str_contains($serialized, 'https://'), 'Replay output leaked an unsafe provider value.');
    $manifest = ['version' => 1, 'cases' => []];
    foreach ($replayProgrammes as $name => $programme) {
        $manifest['cases'][] = replayManifestCase($name, $programme);
    }
    $goldenPath = replayOption('golden');
    if ($goldenPath !== null && file_get_contents($goldenPath) !== $serialized."\n") {
        fwrite(STDERR, "Offline replay golden mismatch.\n");
        exit(1);
    }
    $previewDirectory = replayOption('preview-dir');
    if ($previewDirectory !== null) {
        writeReplayPreview($previewDirectory, $manifest);
    }
    fwrite(STDOUT, $serialized."\n");
}
