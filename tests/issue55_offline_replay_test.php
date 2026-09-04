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
    ], new ReplayTmdbService([], [], [], []));
    [$dazn, $daznTmdb] = $run(['title' => 'DAZN Live', 'category' => 'Sports'], new ReplayTmdbService([], [], [], []));
    $episodeCandidate = ['tmdb_id' => 904, 'name' => 'Serial Beacon', 'original_name' => 'Serial Beacon', 'first_air_date' => '2024-01-01', 'overview' => ''];
    [$episodeValid, $episodeValidTmdb] = $run(['title' => 'Serial Beacon', 'episode_num' => '0.0'], new ReplayTmdbService([$episodeCandidate], [], [904 => tvDetails(904, 'Serial Beacon', 'https://image.tmdb.org/t/p/original/serial.jpg')], [], [
        '904:1' => ['episodes' => [['episode_number' => 1, 'overview' => '', 'still_path' => '/serial-s01e01.jpg']],],
    ]), true);
    [$episodeWrong, $episodeWrongTmdb] = $run(['title' => 'Serial Beacon', 'episode_num' => 'not-an-episode'], new ReplayTmdbService([$episodeCandidate], [], [904 => tvDetails(904, 'Serial Beacon', 'https://image.tmdb.org/t/p/original/serial.jpg')], []), true);

    replayAssert(($catalogue['tmdb_decision']['result'] ?? null) === 'selected' && $catalogueTmdb->tvCandidateSearches === 1, 'Catalogue normalization replay mismatch.');
    replayAssert(($ambiguous['tmdb_decision']['class'] ?? null) === 'ambiguous_identity' && $ambiguousTmdb->tvCandidateSearches === 1 && $ambiguousTmdb->movieCandidateSearches === 1, 'Global ambiguity replay mismatch.');
    replayAssert(($provider['tmdb_decision']['class'] ?? null) === 'provider_art_preserved' && $providerTmdb->tvCandidateSearches + $providerTmdb->movieCandidateSearches === 0, 'Provider preservation replay mismatch.');
    replayAssert(($dazn['tmdb_decision']['class'] ?? null) === 'sports_or_live_fallback' && $daznTmdb->tvCandidateSearches + $daznTmdb->movieCandidateSearches === 0, 'Live applicability replay mismatch.');
    replayAssert(($episodeValid['images'][1]['type'] ?? null) === 'screenshot' && $episodeValidTmdb->seasonRequests === 1, 'Valid episode replay mismatch.');
    replayAssert(! in_array('screenshot', array_column($episodeWrong['images'] ?? [], 'type'), true) && $episodeWrongTmdb->seasonRequests === 0, 'Wrong episode replay mismatch.');

    $output = [];
    foreach (['catalogue' => $catalogue, 'ambiguous' => $ambiguous, 'provider' => $provider, 'live_fallback' => $dazn, 'episode_valid' => $episodeValid, 'episode_wrong' => $episodeWrong] as $name => $programme) {
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
    fwrite(STDOUT, $serialized."\n");
}
