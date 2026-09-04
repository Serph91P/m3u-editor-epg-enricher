<?php

namespace {
    function storage_path(string $path = ''): string
    {
        return '/dev/null';
    }

    function app(string $class): object
    {
        return $GLOBALS['tmdbTestSettings'];
    }
}

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}

    interface PluginSelectOptionsProviderInterface
    {
        public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array;
    }
}

namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}

    class PluginActionResult
    {
        public function __construct(
            public readonly string $status,
            public readonly bool $success,
            public readonly string $summary,
            public readonly array $data = [],
        ) {}

        public static function success(string $summary, array $data = []): self
        {
            return new self('completed', true, $summary, $data);
        }

        public static function failure(string $summary, array $data = []): self
        {
            return new self('failed', false, $summary, $data);
        }

        public static function cancelled(string $summary, array $data = []): self
        {
            return new self('cancelled', false, $summary, $data);
        }
    }
    class PluginExecutionContext {}
}

namespace App\Settings {
    class GeneralSettings
    {
        public string $tmdb_api_key = '';
        public string $tmdb_language = 'de-DE';
    }
}

namespace Illuminate\Support\Facades {
    class FakeHttpResponse
    {
        public function __construct(private bool $successful, private array $data = []) {}

        public function successful(): bool
        {
            return $this->successful;
        }

        public function json(): array
        {
            return $this->data;
        }
    }

    class Http
    {
        public static array $calls = [];
        public static array $responses = [];

        public static function timeout(int $seconds): self
        {
            return new self();
        }

        public function get(string $url, array $query): FakeHttpResponse
        {
            self::$calls[] = ['url' => $url, 'query' => $query];

            return array_shift(self::$responses);
        }
    }

    class Log
    {
        public static function warning(string $message, array $context = []): void {}
    }
}

namespace App\Services {
    class TmdbService
    {
        public int $tvSearches = 0;
        public int $movieSearches = 0;
        public int $tvDetailsRequests = 0;
        public int $movieDetailsRequests = 0;
        public int $tvAlternativeRequests = 0;
        public int $movieAlternativeRequests = 0;
        public int $seasonRequests = 0;

        public function __construct(protected string $scenario) {}

        public function searchTvSeries(string $name, ?int $year = null): ?array
        {
            $this->tvSearches++;

            return match ($this->scenario) {
                'long-walk' => [
                    'tmdb_id' => 100,
                    'name' => 'The Long Walk',
                    'original_name' => 'The Long Walk',
                    'first_air_date' => '2024-01-01',
                ],
                'illuminati' => [
                    'tmdb_id' => 101,
                    'name' => 'Secret Society',
                    'original_name' => 'Secret Society',
                    'first_air_date' => '2015-01-01',
                ],
                'bares' => [
                    'tmdb_id' => 102,
                    'name' => 'Bares für Rares',
                    'original_name' => 'Bares für Rares',
                    'first_air_date' => '2013-08-03',
                ],
                'ghosts' => [
                    'tmdb_id' => 104,
                    'name' => 'Ghosts',
                    'original_name' => 'Ghosts',
                    'first_air_date' => '2019-04-15',
                ],
                'same-name-ghosts' => [
                    'tmdb_id' => $year === 2021 ? 108 : 107,
                    'name' => 'Ghosts',
                    'original_name' => 'Ghosts',
                    'first_air_date' => ($year === 2021 ? '2021' : '2019').'-01-01',
                ],
                'german-series' => [
                    'tmdb_id' => 105,
                    'name' => 'Die Landarztpraxis',
                    'original_name' => 'Die Landarztpraxis',
                    'first_air_date' => '2023-10-16',
                ],
                'ulrich-wetzel' => [
                    'tmdb_id' => 106,
                    'name' => $name,
                    'original_name' => $name,
                    'first_air_date' => '2022-10-10',
                ],
                'ambiguous' => [
                    'tmdb_id' => 103,
                    'name' => 'Crossroads',
                    'original_name' => 'Crossroads',
                    'first_air_date' => '2020-01-01',
                ],
                'identity-tv-a' => [
                    'tmdb_id' => 109,
                    'name' => $name,
                    'original_name' => $name,
                    'first_air_date' => '',
                ],
                'identity-tv-b' => [
                    'tmdb_id' => 110,
                    'name' => $name,
                    'original_name' => $name,
                    'first_air_date' => '',
                ],
                'rote-rosen' => [
                    'tmdb_id' => 27181,
                    'name' => 'Rote Rosen',
                    'original_name' => 'Rote Rosen',
                    'first_air_date' => '2006-11-06',
                ],
                'translation-only' => [
                    'tmdb_id' => 299,
                    'name' => 'Distant Fixture Candidate',
                    'original_name' => 'Distant Fixture Candidate',
                    'first_air_date' => '1900-01-01',
                ],
                default => null,
            };
        }

        public function getTvSeriesDetails(int $tmdbId): mixed
        {
            $this->tvDetailsRequests++;

            $details = match ($this->scenario) {
                'long-walk' => [
                    'overview' => 'An unrelated reality competition.',
                    'poster_url' => 'https://fixture.invalid/tv-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/tv-backdrop.jpg',
                ],
                'illuminati' => ['overview' => 'A modern secret society drama.'],
                'bares' => [
                    'overview' => 'Horst Lichter präsentiert seltene Fundstücke, die anschließend von Händlern ersteigert werden können.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/bares-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/bares-backdrop.jpg',
                    'genres' => 'Reality',
                ],
                'ambiguous' => [
                    'overview' => 'Several lives meet at a crossroads.',
                    'poster_url' => 'https://fixture.invalid/crossroads-tv-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/crossroads-tv-backdrop.jpg',
                ],
                'ghosts' => [
                    'overview' => 'A young couple inherit a country estate occupied by ghosts.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/ghosts-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
                    'genres' => 'Comedy',
                ],
                'same-name-ghosts' => [
                    'overview' => 'A comedy about ghosts.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/ghosts-'.$tmdbId.'-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/ghosts-'.$tmdbId.'-backdrop.jpg',
                    'genres' => 'Comedy',
                ],
                'german-series' => [
                    'overview' => 'Eine Ärztin beginnt ein neues Leben in Wiesenkirchen.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/landarztpraxis-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/landarztpraxis-backdrop.jpg',
                ],
                'ulrich-wetzel' => [
                    'overview' => 'A reality court programme.',
                    'backdrop_url' => 'https://fixture.invalid/court-tv-backdrop.jpg',
                ],
                'identity-tv-a' => [
                    'backdrop_url' => 'https://fixture.invalid/identity-tv-a.jpg',
                ],
                'identity-tv-b' => [
                    'backdrop_url' => 'https://fixture.invalid/identity-tv-b.jpg',
                ],
                'rote-rosen' => [
                    'overview' => 'Eine Telenovela aus Lueneburg.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/rote-rosen-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/qZ1odCAlNZhUIeLXZXU06JxRqjo.jpg',
                    'genres' => 'Soap, Drama',
                ],
                default => null,
            };
            $name = match ($tmdbId) {
                102 => 'Bares für Rares',
                104, 107, 108 => 'Ghosts',
                105 => 'Die Landarztpraxis',
                27181 => 'Rote Rosen',
                default => null,
            };
            if (! is_array($details) || $name === null) {
                return $details;
            }

            return array_merge([
                'tmdb_id' => $tmdbId, 'tvdb_id' => null, 'imdb_id' => null, 'name' => $name, 'original_name' => $name,
                'overview' => null, 'poster_url' => null, 'backdrop_url' => null, 'first_air_date' => null,
                'genres' => '', 'vote_average' => null, 'vote_count' => null, 'status' => null,
                'number_of_seasons' => null, 'number_of_episodes' => null, 'cast' => null, 'director' => null, 'youtube_trailer' => null,
            ], $details);
        }

        public function searchMovie(string $title, ?int $year = null, bool $tryFallback = true, bool $skipYearExtraction = false): ?array
        {
            $this->movieSearches++;

            return match ($this->scenario) {
                'long-walk' => [
                    'tmdb_id' => 200,
                    'title' => 'The Long Walk',
                    'original_title' => 'The Long Walk',
                    'release_date' => '2025-09-11',
                ],
                'illuminati' => [
                    'tmdb_id' => 201,
                    'title' => 'Angels & Demons',
                    'original_title' => 'Angels & Demons',
                    'release_date' => '2009-05-13',
                ],
                'bares' => [
                    'tmdb_id' => 202,
                    'title' => 'Bares für Rares',
                    'original_title' => 'Bares für Rares',
                    'release_date' => '2013-01-01',
                ],
                'ambiguous' => [
                    'tmdb_id' => 203,
                    'title' => 'Crossroads',
                    'original_title' => 'Crossroads',
                    'release_date' => '2020-01-01',
                ],
                'boston' => [
                    'tmdb_id' => 204,
                    'title' => 'Boston',
                    'original_title' => 'Boston',
                    'release_date' => '2017-11-17',
                ],
                'ulrich-wetzel' => [
                    'tmdb_id' => 205,
                    'title' => $title,
                    'original_title' => $title,
                    'release_date' => '2022-10-10',
                ],
                'identity-movie-a' => [
                    'tmdb_id' => 206,
                    'title' => $title,
                    'original_title' => $title,
                    'release_date' => '',
                ],
                'identity-movie-b' => [
                    'tmdb_id' => 207,
                    'title' => $title,
                    'original_title' => $title,
                    'release_date' => '',
                ],
                'localized-poster' => [
                    'tmdb_id' => 208,
                    'title' => 'Localized Poster',
                    'original_title' => 'Localized Poster',
                    'release_date' => '2026-01-01',
                ],
                'poster-only' => [
                    'tmdb_id' => 209,
                    'title' => 'Poster Only',
                    'original_title' => 'Poster Only',
                    'release_date' => '2026-01-01',
                ],
                'backdrop-quality' => [
                    'tmdb_id' => 210,
                    'title' => 'Backdrop Quality',
                    'original_title' => 'Backdrop Quality',
                    'release_date' => '2026-01-01',
                ],
                'translation-only' => [
                    'tmdb_id' => 211,
                    'title' => 'Silent City',
                    'original_title' => 'Silent City',
                    'release_date' => '2024-01-01',
                ],
                default => null,
            };
        }

        public function getMovieDetails(int $tmdbId): mixed
        {
            $this->movieDetailsRequests++;

            $details = match ($this->scenario) {
                'long-walk' => [
                    'overview' => 'In a deadly annual contest, young men must keep walking.',
                    'poster_url' => 'https://fixture.invalid/movie-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/movie-backdrop.jpg',
                    'cast' => ['Cooper Hoffman', 'David Jonsson'],
                    'director' => ['Francis Lawrence'],
                ],
                'illuminati' => [
                    'overview' => 'Robert Langdon investigates a threat against the Vatican.',
                    'poster_url' => 'https://fixture.invalid/illuminati-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/illuminati-backdrop.jpg',
                    'cast' => ['Tom Hanks', 'Ewan McGregor', 'Ayelet Zurer'],
                    'director' => ['Ron Howard'],
                ],
                'bares' => [
                    'overview' => 'Ein deutscher Film über außergewöhnliche Antiquitäten und ihre Geschichte.',
                    'poster_url' => 'https://fixture.invalid/bares-movie-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/bares-movie-backdrop.jpg',
                ],
                'ambiguous' => [
                    'overview' => 'Several lives meet at a crossroads.',
                    'poster_url' => 'https://fixture.invalid/crossroads-movie-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/crossroads-movie-backdrop.jpg',
                ],
                'boston' => [
                    'overview' => 'The story of the Boston Marathon bombing and its aftermath.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/boston-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/boston-backdrop.jpg',
                ],
                'ulrich-wetzel' => [
                    'overview' => 'A reality court programme.',
                    'backdrop_url' => 'https://fixture.invalid/court-movie-backdrop.jpg',
                ],
                'identity-movie-a' => [
                    'backdrop_url' => 'https://fixture.invalid/identity-movie-a.jpg',
                ],
                'identity-movie-b' => [
                    'backdrop_url' => 'https://fixture.invalid/identity-movie-b.jpg',
                ],
                'localized-poster' => [
                    'overview' => 'A localized poster fixture.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/default-poster.jpg',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/localized-backdrop.jpg',
                ],
                'poster-only' => [
                    'overview' => 'A programme without landscape artwork.',
                    'poster_url' => 'https://image.tmdb.org/t/p/w500/poster-only.jpg',
                ],
                'backdrop-quality' => [
                    'overview' => 'A programme used to verify backdrop quality selection.',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/details-backdrop.jpg',
                ],
                'translation-only' => [
                    'overview' => 'A translated catalogue fixture.',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/translation-only.jpg',
                ],
                default => null,
            };
            $title = match ($tmdbId) {
                204 => 'Boston',
                208 => 'Localized Poster',
                209 => 'Poster Only',
                210 => 'Backdrop Quality',
                default => null,
            };
            if (! is_array($details) || $title === null) {
                return $details;
            }

            return array_merge([
                'tmdb_id' => $tmdbId, 'imdb_id' => null, 'title' => $title, 'original_title' => $title,
                'overview' => null, 'poster_url' => null, 'backdrop_url' => null, 'release_date' => null,
                'genres' => '', 'vote_average' => null, 'vote_count' => null, 'runtime' => null, 'status' => null,
                'cast' => [], 'director' => [], 'youtube_trailer' => null,
            ], $details);
        }

        public function getTvAlternativeTitles(int $tmdbId): array
        {
            $this->tvAlternativeRequests++;

            return [];
        }

        public function getMovieAlternativeTitles(int $tmdbId): array
        {
            $this->movieAlternativeRequests++;

            return match ($this->scenario) {
                'long-walk' => [['title' => 'The Long Walk - Der Todesmarsch', 'iso_3166_1' => 'DE']],
                'illuminati' => [['title' => 'Illuminati', 'iso_3166_1' => 'DE']],
                default => [],
            };
        }

        public function getSeasonDetails(int $tmdbId, int $season): ?array
        {
            $this->seasonRequests++;

            return match ($this->scenario) {
                'ghosts' => [
                    'episodes' => [
                        [
                            'episode_number' => 6,
                            'overview' => '',
                            'still_path' => '/ghosts-s01e06.jpg',
                        ],
                        [
                            'episode_number' => 7,
                            'overview' => '',
                            'still_path' => '/ghosts-s01e07.jpg',
                        ],
                    ],
                ],
                default => null,
            };
        }
    }

    class CandidateTmdbService extends TmdbService
    {
        public int $tvCandidateSearches = 0;
        public int $movieCandidateSearches = 0;
        public int $tvTranslationRequests = 0;
        public int $movieTranslationRequests = 0;
        public array $tvLimits = [];
        public array $movieLimits = [];
        public array $tvQueries = [];
        public array $movieQueries = [];

        public function __construct(
            private array $tvCandidates = [],
            private array $movieCandidates = [],
            private array $tvDetails = [],
            private array $movieDetails = [],
            private bool $throwOnTvCandidates = false,
            private bool $throwOnMovieCandidates = false,
            private array $tvCandidatesByQuery = [],
            private array $movieCandidatesByQuery = [],
            private array $tvAlternativeTitles = [],
            private array $movieAlternativeTitles = [],
            private array $tvTranslations = [],
            private array $movieTranslations = [],
            private bool $throwOnTvAlternativeTitles = false,
            private bool $throwOnTvTranslations = false,
            private bool $returnSoleCandidates = false,
        ) {
            parent::__construct('candidate-api');
        }

        public function searchTvSeriesCandidates(string $name, ?int $year = null, int $limit = 5): array
        {
            $this->tvCandidateSearches++;
            $this->tvLimits[] = $limit;
            $this->tvQueries[] = $name;
            if ($this->throwOnTvCandidates) {
                throw new \RuntimeException('synthetic TV candidate failure');
            }

            $candidates = array_key_exists($name, $this->tvCandidatesByQuery)
                ? $this->tvCandidatesByQuery[$name]
                : $this->tvCandidates;

            return $this->withDistantRunner($candidates, 'tv');
        }

        public function searchMovieCandidates(string $title, ?int $year = null, int $limit = 5): array
        {
            $this->movieCandidateSearches++;
            $this->movieLimits[] = $limit;
            $this->movieQueries[] = $title;
            if ($this->throwOnMovieCandidates) {
                throw new \RuntimeException('synthetic movie candidate failure');
            }

            $candidates = array_key_exists($title, $this->movieCandidatesByQuery)
                ? $this->movieCandidatesByQuery[$title]
                : $this->movieCandidates;

            return $this->withDistantRunner($candidates, 'movie');
        }

        private function withDistantRunner(array $candidates, string $mediaType): array
        {
            if ($this->returnSoleCandidates || count($candidates) !== 1) {
                return $candidates;
            }
            $candidates[] = $mediaType === 'tv'
                ? ['tmdb_id' => 2000000001, 'name' => 'Distant Fixture Candidate', 'original_name' => 'Distant Fixture Candidate', 'original_language' => 'en', 'first_air_date' => '1900-01-01', 'overview' => '']
                : ['tmdb_id' => 2000000002, 'title' => 'Distant Fixture Candidate', 'original_title' => 'Distant Fixture Candidate', 'original_language' => 'en', 'release_date' => '1900-01-01', 'overview' => ''];

            return $candidates;
        }

        public function getTvSeriesDetails(int $tmdbId): ?array
        {
            $this->tvDetailsRequests++;

            return $this->tvDetails[$tmdbId] ?? null;
        }

        public function getMovieDetails(int $tmdbId): ?array
        {
            $this->movieDetailsRequests++;

            return $this->movieDetails[$tmdbId] ?? null;
        }

        public function getTvAlternativeTitles(int $tmdbId): array
        {
            $this->tvAlternativeRequests++;
            if ($this->throwOnTvAlternativeTitles) {
                throw new \RuntimeException('synthetic alternative-title failure');
            }

            return $this->tvAlternativeTitles[$tmdbId] ?? [];
        }

        public function getMovieAlternativeTitles(int $tmdbId): array
        {
            $this->movieAlternativeRequests++;

            return $this->movieAlternativeTitles[$tmdbId] ?? [];
        }

        public function getTvTranslations(int $tmdbId): array
        {
            $this->tvTranslationRequests++;
            if ($this->throwOnTvTranslations) {
                throw new \RuntimeException('synthetic translation failure');
            }

            return $this->tvTranslations[$tmdbId] ?? [];
        }

        public function getMovieTranslations(int $tmdbId): array
        {
            $this->movieTranslationRequests++;

            return $this->movieTranslations[$tmdbId] ?? [];
        }
    }

}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Services\TmdbService;
    use App\Services\CandidateTmdbService;
    use App\Settings\GeneralSettings;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\FakeHttpResponse;
    use Illuminate\Support\Facades\Http;
    use ReflectionClass;
    use ReflectionMethod;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    function assertTrueValue(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    function enrich(
        Plugin $plugin,
        ReflectionMethod $method,
        array &$programme,
        TmdbService $tmdb,
        array &$cache,
        array $lookupContext = [],
        ?array &$seasonCache = null,
        ?array &$imagesCache = null,
        bool $enrichEpisodeDetails = false,
        bool $overwrite = false,
        bool $mapGenresToEpgCategories = false,
        bool $mapGenresToKodiGuideGenres = false,
    ): array
    {
        $seasonCache ??= [];
        $imagesCache ??= [];

        $result = $method->invokeArgs($plugin, [
            &$programme,
            $tmdb,
            &$cache,
            $overwrite,
            true,
            true,
            true,
            true,
            $mapGenresToEpgCategories,
            $mapGenresToKodiGuideGenres,
            false,
            $enrichEpisodeDetails,
            &$seasonCache,
            &$imagesCache,
            $lookupContext,
        ]);

        return $result;
    }

    function validatedSearch(
        Plugin $plugin,
        ReflectionMethod $method,
        TmdbService $tmdb,
        string $title,
        ?string $mediaType = null,
        ?int $year = null,
        string $description = '',
        array $localeEvidence = [],
        ?array $externalIdentity = null,
        ?array &$evidence = null,
    ): ?array {
        $provisionalIdentity = null;
        $matchEvidence = null;
        $lookupBudget = null;

        $result = $method->invokeArgs($plugin, [
            $tmdb,
            $title,
            $mediaType,
            $year,
            $description,
            &$provisionalIdentity,
            null,
            &$matchEvidence,
            $localeEvidence,
            &$lookupBudget,
            $externalIdentity,
        ]);
        $evidence = $matchEvidence;

        return $result;
    }

    function normalizedTvDetailsFixture(
        int $tmdbId,
        string $name,
        ?string $overview = null,
        ?string $posterUrl = null,
        ?string $backdropUrl = null,
    ): array {
        return [
            'tmdb_id' => $tmdbId,
            'tvdb_id' => null,
            'imdb_id' => null,
            'name' => $name,
            'original_name' => $name,
            'overview' => $overview,
            'poster_url' => $posterUrl,
            'backdrop_url' => $backdropUrl,
            'first_air_date' => null,
            'genres' => '',
            'vote_average' => null,
            'vote_count' => null,
            'status' => null,
            'number_of_seasons' => null,
            'number_of_episodes' => null,
            'cast' => null,
            'director' => null,
            'youtube_trailer' => null,
        ];
    }

    function normalizedMovieDetailsFixture(
        int $tmdbId,
        string $title,
        ?string $overview = null,
        ?string $posterUrl = null,
        ?string $backdropUrl = null,
    ): array {
        return [
            'tmdb_id' => $tmdbId,
            'imdb_id' => null,
            'title' => $title,
            'original_title' => $title,
            'overview' => $overview,
            'poster_url' => $posterUrl,
            'backdrop_url' => $backdropUrl,
            'release_date' => null,
            'genres' => '',
            'vote_average' => null,
            'vote_count' => null,
            'runtime' => null,
            'status' => null,
            'cast' => [],
            'director' => [],
            'youtube_trailer' => null,
        ];
    }

    $GLOBALS['tmdbTestSettings'] = new GeneralSettings();
    $plugin = new Plugin();
    $reflection = new ReflectionClass($plugin);
    $method = $reflection->getMethod('enrichProgrammeFromTmdb');
    $method->setAccessible(true);
    $searchMethod = $reflection->getMethod('searchTmdbWithValidation');
    $searchMethod->setAccessible(true);
    $sanitizeTmdbCacheMethod = $reflection->getMethod('sanitizeTmdbCache');
    $sanitizeTmdbCacheMethod->setAccessible(true);
    $detectSeriesSignalsMethod = $reflection->getMethod('detectSeriesSignals');
    $detectSeriesSignalsMethod->setAccessible(true);

    $focusedCase = null;
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
        if (str_starts_with($argument, '--focus=')) {
            $focusedCase = substr($argument, 8);
        }
    }

    $locale = static fn (string $requested, ?string $title = null, ?string $description = null): array => [
        'valid' => true,
        'requested_locale' => $requested,
        'title_locale' => $title ?? $requested,
        'description_locale' => $description,
    ];
    $titleComparisonMethod = $reflection->getMethod('titleComparisonResult');
    $titleComparisonMethod->setAccessible(true);

    $focusedCases = [];
    $focusedCases['f1'] = static function () use ($plugin, $searchMethod, $locale): void {
        $soleCandidate = new CandidateTmdbService(
            movieCandidates: [[
                'tmdb_id' => 951,
                'title' => 'Up',
                'original_title' => 'Up',
                'original_language' => 'en',
                'release_date' => '',
                'overview' => '',
            ]],
            movieDetails: [951 => normalizedMovieDetailsFixture(951, 'Up')],
            returnSoleCandidates: true,
        );
        $soleEvidence = null;
        assertSameValue(
            null,
            validatedSearch($plugin, $searchMethod, $soleCandidate, 'Up', null, null, '', $locale('en-US'), null, $soleEvidence),
            'A sole search result with score 80 and no measurable runner-up margin must abstain.'
        );
        assertSameValue(['missing_runner_up_margin', null], [$soleEvidence['reason'] ?? null, $soleEvidence['margin'] ?? null], 'A sole result must report that no measurable runner-up margin exists.');
        assertSameValue([1, 1], [$soleCandidate->tvCandidateSearches, $soleCandidate->movieCandidateSearches], 'The sole-candidate regression must exercise the global candidate search.');
        assertSameValue(0, $soleCandidate->movieDetailsRequests, 'A sole unbound search result must not load winner details.');

        $duplicateCandidate = [
            'tmdb_id' => 951,
            'title' => 'Up',
            'original_title' => 'Up',
            'original_language' => 'en',
            'release_date' => '',
            'overview' => '',
        ];
        $duplicateTmdb = new CandidateTmdbService(movieCandidates: [$duplicateCandidate, $duplicateCandidate]);
        $duplicateEvidence = null;
        assertSameValue(null, validatedSearch($plugin, $searchMethod, $duplicateTmdb, 'Up', 'movie', null, '', $locale('en-US'), null, $duplicateEvidence), 'Duplicate rows for one TMDB identity must not count as distinct runner-up evidence.');
        assertSameValue(['missing_runner_up_margin', null], [$duplicateEvidence['reason'] ?? null, $duplicateEvidence['margin'] ?? null], 'Deduplicated candidates must retain the missing-margin reason.');
    };

    $focusedCases['f2'] = static function () use ($plugin, $method): void {
        $fixtures = [
            ['七人の侍', 'ja-JP', 1954, 952],
            ['ฉลาดเกมส์โกง', 'th-TH', 2017, 953],
            ['المدينة البعيدة', 'ar-SA', 2024, 954],
        ];
        foreach ($fixtures as [$title, $language, $year, $tmdbId]) {
            $tmdb = new CandidateTmdbService(
                movieCandidates: [
                    ['tmdb_id' => $tmdbId, 'title' => $title, 'original_title' => $title, 'original_language' => substr($language, 0, 2), 'release_date' => $year.'-01-01', 'overview' => ''],
                    ['tmdb_id' => $tmdbId + 100, 'title' => 'Unrelated Fixture', 'original_title' => 'Unrelated Fixture', 'original_language' => 'en', 'release_date' => $year.'-01-01', 'overview' => ''],
                ],
                movieDetails: [$tmdbId => normalizedMovieDetailsFixture($tmdbId, $title)],
            );
            $programme = [
                'title' => $title,
                'title_language' => $language,
                'provider_media_type' => 'movie',
                'provider_media_type_trusted' => true,
            ];
            $cache = [];
            enrich($plugin, $method, $programme, $tmdb, $cache, ['tmdb_language' => $language]);
            assertSameValue('selected', $programme['tmdb_decision']['result'] ?? null, $title.' must remain eligible without whitespace-delimited words when trusted structured media evidence is present.');
            assertSameValue(['source' => 'localized'], $programme['tmdb_decision']['selected_title_provenance'] ?? null, $title.' must record localized-title provenance without raw title text.');
            assertSameValue(1, $tmdb->movieDetailsRequests, $title.' must validate exactly one Unicode winner.');
            assertSameValue([0, 1], [$tmdb->tvCandidateSearches, $tmdb->movieCandidateSearches], $title.' must use the trusted movie type without unrelated TV fan-out.');
        }
    };

    $focusedCases['f3'] = static function () use ($plugin, $method): void {
        foreach (['news', 'Nachrichten', 'Journal', 'Live', 'Sky Sport', 'DAZN'] as $title) {
            $tmdb = new CandidateTmdbService();
            $programme = ['title' => $title];
            $cache = [];
            enrich($plugin, $method, $programme, $tmdb, $cache);
            assertSameValue('unknown', $programme['tmdb_decision']['class'] ?? null, $title.' free text must remain UNKNOWN without trusted structured evidence.');
            assertSameValue([0, 0], [$tmdb->tvCandidateSearches, $tmdb->movieCandidateSearches], $title.' free text must not trigger a catalogue search.');
        }
        foreach ([
            ['title' => 'Free Title', 'subtitle' => 'S01E02'],
            ['title' => 'Free Title', 'desc' => 'Season 1 episode 2'],
            ['title' => 'Free Title', 'episode_num' => 'garbage-S01E02-live'],
            ['title' => 'Free Title', 'episode_num' => 'EP123456'],
        ] as $programme) {
            $tmdb = new CandidateTmdbService();
            $cache = [];
            enrich($plugin, $method, $programme, $tmdb, $cache);
            assertSameValue('unknown', $programme['tmdb_decision']['class'] ?? null, 'Untrusted episodic-looking free text must remain UNKNOWN.');
            assertSameValue([0, 0], [$tmdb->tvCandidateSearches, $tmdb->movieCandidateSearches], 'Untrusted episodic-looking free text must not trigger a catalogue search.');
        }

        $episodicKeywordTmdb = new CandidateTmdbService(
            tvCandidates: [['tmdb_id' => 955, 'name' => 'Rote Rosen', 'original_name' => 'Rote Rosen', 'original_language' => 'de', 'first_air_date' => '2006-01-01', 'overview' => '']],
            movieCandidates: [['tmdb_id' => 956, 'title' => 'Rote Rosen', 'original_title' => 'Rote Rosen', 'original_language' => 'de', 'release_date' => '2006-01-01', 'overview' => '']],
        );
        $episodicKeywordProgramme = ['title' => 'Rote Rosen', 'title_language' => 'de-DE', 'date' => '2006'];
        $episodicKeywordCache = [];
        enrich($plugin, $method, $episodicKeywordProgramme, $episodicKeywordTmdb, $episodicKeywordCache, ['tmdb_language' => 'de-DE']);
        assertSameValue([1, 1], [$episodicKeywordTmdb->tvCandidateSearches, $episodicKeywordTmdb->movieCandidateSearches], 'A fixed title keyword must not force TV without exact episode structure.');
    };

    $focusedCases['f4'] = static function () use ($plugin, $method, $reflection): void {
        $externalIdentityMethod = $reflection->getMethod('validatedTypeBoundExternalIdentity');
        $externalIdentityMethod->setAccessible(true);
        foreach ([
            [['tmdb_id' => 951, 'tmdb_media_type' => 'movie'], ['system' => 'tmdb', 'id' => 951, 'media_type' => 'movie']],
            [['imdb_id' => 'tt0111161', 'imdb_media_type' => 'movie'], ['system' => 'imdb', 'id' => 'tt0111161', 'media_type' => 'movie']],
            [['tvdb_id' => 81189, 'tvdb_media_type' => 'tv'], ['system' => 'tvdb', 'id' => 81189, 'media_type' => 'tv']],
            [['external_ids' => [['system' => 'tmdb', 'id' => 951, 'media_type' => 'movie']]], ['system' => 'tmdb', 'id' => 951, 'media_type' => 'movie']],
        ] as [$input, $expected]) {
            assertSameValue($expected, $externalIdentityMethod->invoke($plugin, $input), 'Canonical allowlisted type-bound identifiers must validate deterministically.');
        }
        foreach ([
            ['tmdb_id' => 0, 'tmdb_media_type' => 'movie'],
            ['tmdb_id' => 2147483648, 'tmdb_media_type' => 'movie'],
            ['imdb_id' => 'tt0000000', 'imdb_media_type' => 'movie'],
            ['tvdb_id' => -1, 'tvdb_media_type' => 'tv'],
            ['tvdb_id' => 42, 'tvdb_media_type' => 'movie'],
            ['tmdb_id' => 951, 'tmdb_media_type' => 'movie', 'imdb_id' => 'tt0111161', 'imdb_media_type' => 'movie'],
            ['external_ids' => [
                ['system' => 'tmdb', 'id' => 951, 'media_type' => 'movie'],
                ['system' => 'tmdb', 'id' => 952, 'media_type' => 'movie'],
            ]],
        ] as $input) {
            assertSameValue(null, $externalIdentityMethod->invoke($plugin, $input), 'Out-of-range, malformed, or type-incompatible identifiers must be rejected.');
        }

        $invalidIdentifiers = [
            'non-numeric TMDB ID' => ['tmdb_id' => 'not-an-id'],
            'arbitrary URL' => ['tmdb_id' => 'https://untrusted.invalid/1'],
            'malformed IMDb ID' => ['imdb_id' => 'tt12x'],
            'malformed TVDB ID' => ['tvdb_id' => '1e3'],
            'untyped numeric TMDB ID' => ['tmdb_id' => 951],
        ];
        foreach ($invalidIdentifiers as $label => $identifier) {
            $tmdb = new CandidateTmdbService(movieCandidates: [[
                'tmdb_id' => 951,
                'title' => 'Up',
                'original_title' => 'Up',
                'original_language' => 'en',
                'release_date' => '',
                'overview' => '',
            ]]);
            $programme = array_merge(['title' => 'Up'], $identifier);
            $cache = [];
            enrich($plugin, $method, $programme, $tmdb, $cache, ['tmdb_language' => 'en-US']);
            assertSameValue([0, 0], [$tmdb->tvCandidateSearches, $tmdb->movieCandidateSearches], ucfirst($label).' must be neutral and cannot bypass short-title applicability.');
            assertSameValue([0, 0, 0, 0, 0, 0], [$tmdb->tvDetailsRequests, $tmdb->movieDetailsRequests, $tmdb->tvAlternativeRequests, $tmdb->movieAlternativeRequests, $tmdb->tvTranslationRequests, $tmdb->movieTranslationRequests], ucfirst($label).' must not trigger identity-detail or title-evidence requests.');
            assertSameValue([], $cache, ucfirst($label).' must not create a durable identity cache entry.');
        }

        $unresolvedImdbTmdb = new CandidateTmdbService(movieCandidates: [[
            'tmdb_id' => 951,
            'title' => 'Up',
            'original_title' => 'Up',
            'original_language' => 'en',
            'release_date' => '',
            'overview' => '',
        ]]);
        $unresolvedImdbProgramme = ['title' => 'Up', 'imdb_id' => 'tt0111161', 'imdb_media_type' => 'movie'];
        $unresolvedImdbCache = [];
        enrich($plugin, $method, $unresolvedImdbProgramme, $unresolvedImdbTmdb, $unresolvedImdbCache, ['tmdb_language' => 'en-US']);
        assertSameValue([0, 0], [$unresolvedImdbTmdb->tvCandidateSearches, $unresolvedImdbTmdb->movieCandidateSearches], 'A syntactically valid but unresolved IMDb ID cannot make a short title applicable.');

        $boundTmdb = new CandidateTmdbService(
            movieCandidates: [[
                'tmdb_id' => 951,
                'title' => 'Up',
                'original_title' => 'Up',
                'original_language' => 'en',
                'release_date' => '',
                'overview' => '',
            ]],
            movieDetails: [951 => normalizedMovieDetailsFixture(951, 'Up')],
        );
        $boundProgramme = ['title' => 'Up', 'tmdb_id' => 951, 'tmdb_media_type' => 'movie'];
        $boundCache = [];
        enrich($plugin, $method, $boundProgramme, $boundTmdb, $boundCache, ['tmdb_language' => 'en-US']);
        assertSameValue('selected', $boundProgramme['tmdb_decision']['result'] ?? null, 'A canonical type-bound TMDB ID may select only after expected-type details validation.');
        assertSameValue([0, 0, 1], [$boundTmdb->tvCandidateSearches, $boundTmdb->movieCandidateSearches, $boundTmdb->movieDetailsRequests], 'A type-bound TMDB ID must resolve exactly one expected-type details record without a search.');

        $mismatchedDetailsTmdb = new CandidateTmdbService(
            movieDetails: [951 => normalizedMovieDetailsFixture(952, 'Up')],
        );
        $mismatchedDetailsProgramme = ['title' => 'Up', 'tmdb_id' => 951, 'tmdb_media_type' => 'movie'];
        $mismatchedDetailsCache = [];
        enrich($plugin, $method, $mismatchedDetailsProgramme, $mismatchedDetailsTmdb, $mismatchedDetailsCache, ['tmdb_language' => 'en-US']);
        assertSameValue('unmatched', $mismatchedDetailsProgramme['tmdb_decision']['result'] ?? null, 'Details for a different TMDB identity must fail closed.');
        assertSameValue([0, 0, 1], [$mismatchedDetailsTmdb->tvCandidateSearches, $mismatchedDetailsTmdb->movieCandidateSearches, $mismatchedDetailsTmdb->movieDetailsRequests], 'Mismatched bound details must use only the expected direct details request.');
        assertSameValue([], $mismatchedDetailsCache, 'Mismatched bound details must not create a durable cache entry.');

        $unavailableBoundTmdb = new CandidateTmdbService();
        $unavailableBoundProgramme = ['title' => 'Up', 'tmdb_id' => 951, 'tmdb_media_type' => 'movie'];
        $unavailableBoundCache = [];
        enrich($plugin, $method, $unavailableBoundProgramme, $unavailableBoundTmdb, $unavailableBoundCache, ['tmdb_language' => 'en-US']);
        enrich($plugin, $method, $unavailableBoundProgramme, $unavailableBoundTmdb, $unavailableBoundCache, ['tmdb_language' => 'en-US']);
        assertSameValue(2, $unavailableBoundTmdb->movieDetailsRequests, 'Unavailable type-bound details must be retried rather than durably negative-cached.');
        assertSameValue([], $unavailableBoundCache, 'Unavailable or incomplete type-bound details must not create a durable cache claim.');
    };

    $focusedCases['f5'] = static function () use ($plugin, $method, $reflection): void {
        $localeChainMethod = $reflection->getMethod('buildTmdbLocaleContext');
        $localeChainMethod->setAccessible(true);
        $scriptLocale = $localeChainMethod->invoke($plugin, ['title_language' => 'zh-Hans'], ['tmdb_language' => 'zh-Hant-TW']);
        assertSameValue(true, $scriptLocale['valid'] ?? null, 'A valid BCP-47 script tag must not be classified as malformed.');
        assertSameValue('zh-Hans-TW', $scriptLocale['title_locale'] ?? null, 'A source script and inherited compatible region must remain distinct in BCP-47 provenance.');
        assertSameValue('zh-TW', $scriptLocale['requested_locale'] ?? null, 'TMDB query locale must omit the BCP-47 script instead of treating it as a country.');
        $unrepresentableLocale = $localeChainMethod->invoke($plugin, ['title_language' => 'haw-US'], ['tmdb_language' => 'haw-US']);
        assertSameValue([false, 'tmdb_locale_unrepresentable'], [$unrepresentableLocale['valid'] ?? null, $unrepresentableLocale['reason'] ?? null], 'A valid BCP-47 language without an ISO-639-1 TMDB locale must fail closed without being called malformed.');

        $alternativeTmdb = new CandidateTmdbService(
            tvCandidates: [[
                'tmdb_id' => 957,
                'name' => 'The Bureau',
                'original_name' => 'Le Bureau',
                'original_language' => 'fr',
                'first_air_date' => '2015-01-01',
                'overview' => '',
            ]],
            tvDetails: [957 => normalizedTvDetailsFixture(957, 'The Bureau')],
            tvAlternativeTitles: [957 => [['title' => 'Le Bureau des légendes', 'iso_3166_1' => 'FR', 'type' => 'broadcast']]],
            tvTranslations: [957 => []],
        );
        $programme = [
            'title' => 'Le Bureau des légendes',
            'title_language' => 'fr-FR',
            'date' => '2015',
            'tmdb_id' => 957,
            'tmdb_media_type' => 'tv',
        ];
        $cache = [];
        enrich($plugin, $method, $programme, $alternativeTmdb, $cache, ['tmdb_language' => 'fr-FR']);
        assertSameValue(
            ['source' => 'alternative', 'region' => 'FR', 'type' => 'broadcast'],
            $programme['tmdb_decision']['selected_title_provenance'] ?? null,
            'Selected diagnostics must retain privacy-safe alternative-title region and type provenance.'
        );
        assertSameValue('fr-FR', $programme['tmdb_decision']['scope']['title_language'] ?? null, 'Decision scope must preserve the validated source BCP-47 title locale separately from the TMDB query locale.');
        assertSameValue([0, 0, 1, 1, 1], [$alternativeTmdb->tvCandidateSearches, $alternativeTmdb->movieCandidateSearches, $alternativeTmdb->tvDetailsRequests, $alternativeTmdb->tvAlternativeRequests, $alternativeTmdb->tvTranslationRequests], 'Type-bound alternative-title validation must keep details and title-channel fan-out exact and bounded.');
    };

    if ($focusedCase !== null) {
        if (! isset($focusedCases[$focusedCase])) {
            fwrite(STDERR, "Unknown focused case: {$focusedCase}\n");
            exit(2);
        }
        $focusedCases[$focusedCase]();
        fwrite(STDOUT, strtoupper($focusedCase)." focused regression passed.\n");
        exit(0);
    }
    foreach ($focusedCases as $case) {
        $case();
    }

    assertSameValue(
        ['score' => 1.0, 'compatibility_only' => false, 'script_relation' => 'same'],
        $titleComparisonMethod->invoke($plugin, "I\u{0307}stanbul Hatırası", 'İstanbul Hatırası'),
        'Turkish dotted-I composed and decomposed forms must have one canonical comparison identity.'
    );

    $compatibilityOnlyTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 900,
            'name' => 'Wide Signal',
            'original_name' => 'Wide Signal',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [900 => normalizedTvDetailsFixture(900, 'Wide Signal')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $compatibilityOnlyTmdb, 'Ｗｉｄｅ Ｓｉｇｎａｌ', null, null, '', $locale('en-US')),
        'A compatibility-folded full-width identity must not be accepted without independent evidence.'
    );
    assertSameValue(
        900,
        validatedSearch($plugin, $searchMethod, $compatibilityOnlyTmdb, 'Ｗｉｄｅ Ｓｉｇｎａｌ', 'tv', 2024, '', $locale('en-US'))['tmdb_id'] ?? null,
        'A full-width compatibility key may contribute only when exact year and media-type evidence independently corroborate it.'
    );

    $unicodeCases = [
        ['canonical NFC/NFD', "Cafe\u{0301} du Port", 'Café du Port', 'fr-FR'],
        ['Turkish dotted I canonical form', "I\u{0307}stanbul Hatırası", 'İstanbul Hatırası', 'tr-TR'],
        ['Greek final sigma case fold', 'Οδυσσεύς στο Νησί', 'ΟΔΥΣΣΕΎΣ ΣΤΟ ΝΗΣΊ', 'el-GR'],
        ['French diacritics', 'L’été à Noël', 'L’ÉTÉ À NOËL', 'fr-FR'],
        ['Spanish diacritics', 'El corazón perdido', 'EL CORAZÓN PERDIDO', 'es-ES'],
        ['Polish diacritics', 'Zażółć gęślą jaźń', 'ZAŻÓŁĆ GĘŚLĄ JAŹŃ', 'pl-PL'],
        ['German diacritics', 'Straße über Köln', 'STRASSE ÜBER KÖLN', 'de-DE'],
        ['Cyrillic identity', 'Тихий город', 'ТИХИЙ ГОРОД', 'ru-RU'],
        ['Greek identity', 'Σιωπηλή πόλη', 'ΣΙΩΠΗΛΉ ΠΌΛΗ', 'el-GR'],
        ['Arabic RTL identity', 'مدينة هادئة', 'مدينة هادئة', 'ar-SA'],
        ['CJK identity', '静かな街', '静かな街', 'ja-JP'],
    ];
    foreach ($unicodeCases as $index => [$label, $programmeTitle, $candidateTitle, $requestedLocale]) {
        $tmdbId = 910 + $index;
        $unicodeTmdb = new CandidateTmdbService(
            tvCandidates: [[
                'tmdb_id' => $tmdbId,
                'name' => $candidateTitle,
                'original_name' => $candidateTitle,
                'original_language' => substr($requestedLocale, 0, 2),
                'first_air_date' => '2024-01-01',
                'overview' => '',
            ]],
            tvDetails: [$tmdbId => normalizedTvDetailsFixture($tmdbId, $candidateTitle)],
        );
        $unicodeResult = validatedSearch($plugin, $searchMethod, $unicodeTmdb, $programmeTitle, 'tv', 2024, '', $locale($requestedLocale));
        assertSameValue(
            [$tmdbId, 'selected'],
            [$unicodeResult['tmdb_id'] ?? null, $unicodeResult['_match_evidence']['reason'] ?? null],
            ucfirst($label).' must match through guarded Unicode identity comparison.'
        );
    }

    $dotlessMismatchTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 930,
            'name' => 'Isiklar Gecesi',
            'original_name' => 'Isiklar Gecesi',
            'original_language' => 'tr',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [930 => normalizedTvDetailsFixture(930, 'Isiklar Gecesi')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $dotlessMismatchTmdb, 'Işıklar Gecesi', 'tv', 2024, '', $locale('tr-TR')),
        'Turkish dotless-I and diacritic differences must not collapse into ASCII identity.'
    );

    $confusableTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 931,
            'name' => 'Cosmos Signal',
            'original_name' => 'Cosmos Signal',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [931 => normalizedTvDetailsFixture(931, 'Cosmos Signal')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $confusableTmdb, 'Сosmos Signal', 'tv', 2024, '', $locale('en-US')),
        'Latin/Cyrillic confusable similarity must fail closed even with year and media-type evidence.'
    );

    $unlistedScriptTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 938,
            'name' => 'AA Signal',
            'original_name' => 'AA Signal',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [938 => normalizedTvDetailsFixture(938, 'AA Signal')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $unlistedScriptTmdb, 'AᎪ Signal', 'tv', 2024, '', $locale('en-US')),
        'Mixed-script rejection must discover scripts through Unicode properties rather than a script allowlist.'
    );

    $scriptDigitTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 943,
            'name' => '1234 Signal',
            'original_name' => '1234 Signal',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [943 => normalizedTvDetailsFixture(943, '1234 Signal')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $scriptDigitTmdb, '123٤ Signal', 'tv', 2024, '', $locale('en-US')),
        'Script-specific number characters must participate in mixed-script rejection.'
    );

    $punctuationOnlyTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 939,
            'name' => 'Signal Guard',
            'original_name' => 'Signal Guard',
            'first_air_date' => '',
            'overview' => '',
        ]],
        tvDetails: [939 => normalizedTvDetailsFixture(939, 'Signal Guard')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $punctuationOnlyTmdb, 'Signal: Guard', null, null, '', $locale('en-US')),
        'Punctuation-erased equality is an additional compatibility key and cannot prove identity by itself.'
    );

    $originalTitleTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 940,
            'name' => 'The House',
            'original_name' => 'La casa',
            'original_language' => 'es',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [940 => normalizedTvDetailsFixture(940, 'The House')],
    );
    $originalTitleResult = validatedSearch($plugin, $searchMethod, $originalTitleTmdb, 'La casa', 'tv', 2024, '', $locale('en-US', 'es-ES'));
    assertSameValue(940, $originalTitleResult['tmdb_id'] ?? null, 'Original-title evidence must be evaluated only with its compatible original_language tag.');
    assertSameValue(['source' => 'original'], $originalTitleResult['_match_evidence']['selected_title_provenance'] ?? null, 'Original-title selection must retain its privacy-safe provenance.');

    $aliasCandidate = [[
        'tmdb_id' => 932,
        'name' => 'The Bureau',
        'original_name' => 'Le Bureau',
        'original_language' => 'fr',
        'first_air_date' => '2015-01-01',
        'overview' => '',
    ]];
    $aliasDetails = [932 => normalizedTvDetailsFixture(932, 'The Bureau')];
    $lateSameScriptAlias = array_merge(
        array_map(static fn (int $index): array => ['title' => 'Alias Fixture '.$index, 'iso_3166_1' => 'FR'], range(1, 14)),
        [['title' => 'Le Bureau des légendes', 'iso_3166_1' => 'FR']],
    );
    $sameScriptAliasTmdb = new CandidateTmdbService(
        tvCandidates: $aliasCandidate,
        tvDetails: $aliasDetails,
        tvAlternativeTitles: [932 => $lateSameScriptAlias],
        tvTranslations: [932 => []],
    );
    $sameScriptAliasResult = validatedSearch($plugin, $searchMethod, $sameScriptAliasTmdb, 'Le Bureau des légendes', 'tv', 2015, '', $locale('fr-FR'));
    assertSameValue(932, $sameScriptAliasResult['tmdb_id'] ?? null, 'A region-tagged same-script TMDB alternative title plus year/type evidence should match.');
    assertSameValue(['source' => 'alternative', 'region' => 'FR', 'type' => null], $sameScriptAliasResult['_match_evidence']['selected_title_provenance'] ?? null, 'Alternative-title selection must retain region and type provenance.');
    assertSameValue([1, 1], [$sameScriptAliasTmdb->tvAlternativeRequests, $sameScriptAliasTmdb->tvTranslationRequests], 'A plausible alias candidate must make exactly one bounded alternative-title and translation request.');

    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'fixture-key';
    Http::$calls = [];
    Http::$responses = [new FakeHttpResponse(true, ['translations' => [[
        'iso_639_1' => 'ja',
        'iso_3166_1' => 'JP',
        'data' => ['title' => '静かな街'],
    ]]])];
    $serviceFallbackWinner = validatedSearch(
        $plugin,
        $searchMethod,
        new TmdbService('translation-only'),
        '静かな街',
        null,
        2024,
        '',
        $locale('en-US', 'ja-JP'),
    );
    assertSameValue(211, $serviceFallbackWinner['tmdb_id'] ?? null, 'The production HTTP fallback must expose tagged translations when the host service lacks that method.');
    assertSameValue(['source' => 'translation', 'language' => 'ja', 'region' => 'JP'], $serviceFallbackWinner['_match_evidence']['selected_title_provenance'] ?? null, 'Translation selection must retain language and region provenance.');
    assertSameValue(1, count(Http::$calls), 'A host without translation support must make exactly one bounded fallback request for the plausible candidate.');
    assertTrueValue(str_ends_with(Http::$calls[0]['url'] ?? '', '/movie/211/translations'), 'The fallback request must use the existing TMDB translations endpoint only.');
    $failedFallbackPlugin = new Plugin();
    Http::$calls = [];
    Http::$responses = [new FakeHttpResponse(false), new FakeHttpResponse(false)];
    foreach (range(1, 2) as $_) {
        assertSameValue(
            null,
            validatedSearch($failedFallbackPlugin, $searchMethod, new TmdbService('translation-only'), '静かな街', null, 2024, '', $locale('en-US', 'ja-JP')),
            'An unsuccessful translation response must abstain.'
        );
    }
    assertSameValue(2, count(Http::$calls), 'An unsuccessful or rate-limited title-evidence response must not become a durable negative cache claim.');
    $GLOBALS['tmdbTestSettings']->tmdb_api_key = '';

    $irrelevantRegionalAlternatives = array_map(
        static fn (int $index): array => ['title' => 'Irrelevant Alias '.$index, 'iso_3166_1' => 'JP'],
        range(1, 15),
    );
    $mixedScriptAliasTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 933,
            'name' => 'Tokyo Vice',
            'original_name' => 'Tokyo Vice',
            'original_language' => 'en',
            'first_air_date' => '2022-01-01',
            'overview' => '',
        ]],
        tvDetails: [933 => normalizedTvDetailsFixture(933, 'Tokyo Vice')],
        tvAlternativeTitles: [933 => $irrelevantRegionalAlternatives],
        tvTranslations: [933 => [[
            'iso_639_1' => 'ja',
            'iso_3166_1' => 'JP',
            'data' => ['name' => 'Tokyo Vice 東京'],
        ]]],
    );
    assertSameValue(
        933,
        validatedSearch($plugin, $searchMethod, $mixedScriptAliasTmdb, 'Tokyo Vice 東京', 'tv', 2022, '', $locale('ja-JP'))['tmdb_id'] ?? null,
        'A legitimate mixed-script title requires an explicit tagged TMDB alias and independent evidence.'
    );
    validatedSearch($plugin, $searchMethod, $mixedScriptAliasTmdb, 'Tokyo Vice 東京', 'tv', 2022, '', $locale('ja-JP'));
    assertSameValue([1, 1], [$mixedScriptAliasTmdb->tvAlternativeRequests, $mixedScriptAliasTmdb->tvTranslationRequests], 'Tagged title evidence must be cached and must not fan out on repeat validation.');

    $exactMixedScriptAliasTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 941,
            'name' => 'Tokyo Vice 東京',
            'original_name' => 'Tokyo Vice',
            'original_language' => 'en',
            'first_air_date' => '2022-01-01',
            'overview' => '',
        ]],
        tvDetails: [941 => normalizedTvDetailsFixture(941, 'Tokyo Vice 東京')],
        tvAlternativeTitles: [941 => []],
        tvTranslations: [941 => [[
            'iso_639_1' => 'ja',
            'iso_3166_1' => 'JP',
            'data' => ['name' => 'Tokyo Vice 東京'],
        ]]],
    );
    assertSameValue(
        941,
        validatedSearch($plugin, $searchMethod, $exactMixedScriptAliasTmdb, 'Tokyo Vice 東京', 'tv', 2022, '', $locale('ja-JP'))['tmdb_id'] ?? null,
        'An exact mixed-script localized title must still require and accept equal tagged alias evidence.'
    );

    $missingAliasTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 934,
            'name' => 'Silent City',
            'original_name' => 'Silent City',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvAlternativeTitles: [934 => []],
        tvTranslations: [934 => []],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $missingAliasTmdb, '静かな街', 'tv', 2024, '', $locale('ja-JP')),
        'Missing TMDB translation coverage must remain an explicit neutral gap, not a fuzzy match.'
    );
    assertSameValue([1, 1, 0], [$missingAliasTmdb->tvAlternativeRequests, $missingAliasTmdb->tvTranslationRequests, $missingAliasTmdb->tvDetailsRequests], 'Missing aliases must use one bounded evidence expansion and no winner-details request.');

    $failingAliasTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 942,
            'name' => 'Failure City',
            'original_name' => 'Failure City',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        throwOnTvAlternativeTitles: true,
    );
    validatedSearch($plugin, $searchMethod, $failingAliasTmdb, '失敗の街', 'tv', 2024, '', $locale('ja-JP'));
    validatedSearch($plugin, $searchMethod, $failingAliasTmdb, '失敗の街', 'tv', 2024, '', $locale('ja-JP'));
    assertSameValue([2, 2], [$failingAliasTmdb->tvAlternativeRequests, $failingAliasTmdb->tvTranslationRequests], 'A failed title-evidence channel must not block the other channel or create a durable negative cache claim.');

    $translationFailureTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 944,
            'name' => 'The Bureau',
            'original_name' => 'Le Bureau',
            'original_language' => 'fr',
            'first_air_date' => '2015-01-01',
            'overview' => '',
        ]],
        tvDetails: [944 => normalizedTvDetailsFixture(944, 'The Bureau')],
        tvAlternativeTitles: [944 => [['title' => 'Le Bureau des légendes', 'iso_3166_1' => 'FR']]],
        throwOnTvTranslations: true,
    );
    assertSameValue(944, validatedSearch($plugin, $searchMethod, $translationFailureTmdb, 'Le Bureau des légendes', 'tv', 2015, '', $locale('fr-FR'))['tmdb_id'] ?? null, 'A translation failure must retain independently valid alternative-title evidence.');
    assertSameValue([1, 1], [$translationFailureTmdb->tvAlternativeRequests, $translationFailureTmdb->tvTranslationRequests], 'Independent title-evidence channels must each make at most one request.');

    $boundedAlternatives = array_map(
        static fn (int $index): array => ['title' => 'Bounded Alias '.$index, 'iso_3166_1' => 'FR'],
        range(1, 50),
    );
    $boundedAlternatives[] = ['title' => 'Alias Beyond Bound', 'iso_3166_1' => 'FR'];
    $boundedEvidenceTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 945,
            'name' => 'Bounded Evidence',
            'original_name' => 'Bounded Evidence',
            'original_language' => 'en',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvAlternativeTitles: [945 => $boundedAlternatives],
        tvTranslations: [945 => []],
    );
    assertSameValue(null, validatedSearch($plugin, $searchMethod, $boundedEvidenceTmdb, 'Alias Beyond Bound', 'tv', 2024, '', $locale('fr-FR')), 'Title evidence beyond the per-channel 50-record bound must remain unavailable.');
    assertSameValue([1, 1, 0], [$boundedEvidenceTmdb->tvAlternativeRequests, $boundedEvidenceTmdb->tvTranslationRequests, $boundedEvidenceTmdb->tvDetailsRequests], 'The title-record bound must not increase endpoint or winner-details request counts.');

    $descriptionLanguageTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 935,
            'name' => 'Signal Harborxyz',
            'original_name' => 'Signal Harborxyz',
            'original_language' => 'en',
            'first_air_date' => '',
            'overview' => 'shared lighthouse harbour mystery',
            'cast' => ['Ada Person', 'Ben Person'],
        ]],
        tvDetails: [935 => normalizedTvDetailsFixture(935, 'Signal Harborxyz')],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $descriptionLanguageTmdb, 'Signal Harbor', null, null, 'Ada Person and Ben Person share a lighthouse harbour mystery', $locale('en-US', 'en-US', 'es-ES')),
        'A language-incompatible description must be neutral and cannot rescue a weak title match.'
    );

    foreach (['zz-ZZ' => 'unsupported_language_tag', 'en-UK' => 'unsupported_language_tag', 'en-US-Latn' => 'malformed_language_tag'] as $badLocale => $expectedReason) {
        $badLocaleTmdb = new CandidateTmdbService(tvCandidates: [[
            'tmdb_id' => 936,
            'name' => 'Locale Guard',
            'original_name' => 'Locale Guard',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]]);
        $badLocaleProgramme = ['title' => 'Locale Guard', 'title_language' => $badLocale, 'date' => '2024'];
        $badLocaleCache = [];
        enrich($plugin, $method, $badLocaleProgramme, $badLocaleTmdb, $badLocaleCache, ['tmdb_language' => 'en-US']);
        assertSameValue([0, 0], [$badLocaleTmdb->tvCandidateSearches, $badLocaleTmdb->movieCandidateSearches], ucfirst($badLocale).' must fail before TMDB lookup.');
        assertSameValue($expectedReason, $badLocaleProgramme['tmdb_decision']['reason'] ?? null, ucfirst($badLocale).' must retain a deterministic rejection reason.');
    }

    $unsafeConfiguredLocaleTmdb = new CandidateTmdbService();
    $unsafeConfiguredLocaleProgramme = ['title' => 'Locale Guard'];
    $unsafeConfiguredLocaleCache = [];
    enrich($plugin, $method, $unsafeConfiguredLocaleProgramme, $unsafeConfiguredLocaleTmdb, $unsafeConfiguredLocaleCache, ['tmdb_language' => 'https://secret.invalid/token']);
    assertSameValue('__invalid', $unsafeConfiguredLocaleProgramme['tmdb_decision']['scope']['language'] ?? null, 'Malformed configured locale diagnostics must use a constant redacted scope value.');
    assertTrueValue(! str_contains(json_encode($unsafeConfiguredLocaleProgramme['tmdb_decision'], JSON_UNESCAPED_SLASHES), 'secret.invalid'), 'Malformed configured locale diagnostics must not leak the raw setting.');

    $unsafeLegacyLocaleTmdb = new TmdbService('long-walk');
    $unsafeLegacyLocaleProgramme = ['title' => 'The Long Walk'];
    $unsafeLegacyLocaleCache = [];
    enrich($plugin, $method, $unsafeLegacyLocaleProgramme, $unsafeLegacyLocaleTmdb, $unsafeLegacyLocaleCache, ['tmdb_language' => 'https://secret.invalid/token']);
    assertSameValue([0, 0], [$unsafeLegacyLocaleTmdb->tvSearches, $unsafeLegacyLocaleTmdb->movieSearches], 'Malformed configured locales must not enter the legacy lookup path.');

    $localeChainMethod = $reflection->getMethod('buildTmdbLocaleContext');
    $localeChainMethod->setAccessible(true);
    assertSameValue(
        [
            'valid' => true,
            'reason' => 'validated_locale_chain',
            'requested_locale' => 'fr-CA',
            'title_locale' => 'fr-CA',
            'description_locale' => 'fr-CA',
        ],
        $localeChainMethod->invoke($plugin, ['title_language' => 'fr', 'desc_language' => 'fr'], ['tmdb_language' => 'fr-CA']),
        'A language-only XMLTV tag must inherit a compatible configured TMDB region deterministically.'
    );
    foreach (['aa-US', 'en-AQ'] as $validSparseLocale) {
        assertSameValue(true, $localeChainMethod->invoke($plugin, ['title_language' => $validSparseLocale], ['tmdb_language' => $validSparseLocale])['valid'] ?? null, $validSparseLocale.' must remain valid even when ICU has no tailored locale bundle.');
    }

    $missedTitleLogPath = sys_get_temp_dir().'/epg-enricher-legacy-missed-title.jsonl';
    $safeMissedFingerprint = str_repeat('a', 64);
    file_put_contents($missedTitleLogPath, json_encode(['ts' => '2026-09-04T00:00:00+00:00', 'title' => 'Legacy Raw Title', 'base' => 'Legacy Raw'])."\n".json_encode(['ts' => '2026-09-04T00:00:00+00:00', 'identity_fingerprint' => $safeMissedFingerprint, 'year' => 2024, 'forced_type' => 'tv'])."\n");
    $scrubMissedTitleLogMethod = $reflection->getMethod('scrubLegacyMissedTitleLog');
    $scrubMissedTitleLogMethod->setAccessible(true);
    $scrubMissedTitleLogMethod->invoke($plugin, $missedTitleLogPath);
    $scrubbedMissedTitleLog = (string) file_get_contents($missedTitleLogPath);
    assertTrueValue(! str_contains($scrubbedMissedTitleLog, 'Legacy Raw') && str_contains($scrubbedMissedTitleLog, $safeMissedFingerprint), 'Legacy raw missed-title rows must be removed while privacy-safe fingerprint rows survive migration.');
    unlink($missedTitleLogPath);

    $rawUnicodeTitle = "Cafe\u{0301} du Port";
    $rawUnicodeTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 937,
            'name' => 'Café du Port',
            'original_name' => 'Café du Port',
            'original_language' => 'fr',
            'first_air_date' => '2024-01-01',
            'overview' => '',
        ]],
        tvDetails: [937 => normalizedTvDetailsFixture(937, 'Café du Port')],
    );
    $rawUnicodeProgramme = ['title' => $rawUnicodeTitle, 'title_language' => 'fr-FR', 'episode_num' => '0.0'];
    $rawUnicodeCache = [];
    enrich($plugin, $method, $rawUnicodeProgramme, $rawUnicodeTmdb, $rawUnicodeCache, ['tmdb_language' => 'fr-FR']);
    assertSameValue($rawUnicodeTitle, $rawUnicodeProgramme['title'], 'Unicode comparison keys must never replace the raw UTF-8 programme title.');

    assertSameValue(
        [
            'is_series_episode' => true,
            'season' => 23,
            'episode' => 7,
            'confidence' => 'high',
        ],
        $detectSeriesSignalsMethod->invoke($plugin, [
            'subtitle' => 'Episode fixture',
            'episode_num' => '22.6.',
            'episode_nums' => [
                ['system' => 'xmltv_ns', 'value' => '22.6.'],
            ],
        ]),
        'A trailing-dot XMLTV NS value should resolve its zero-based season and episode.'
    );

    $genericClassicDetails = normalizedTvDetailsFixture(
        701,
        'Beacon Vale',
        'A synthetic series used to verify generic edition-title matching.',
        'https://image.tmdb.org/t/p/w500/beacon-vale-poster.jpg',
        'https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg',
    );
    $genericClassicCandidate = [
        'tmdb_id' => 701,
        'name' => 'Beacon Vale',
        'original_name' => 'Beacon Vale',
        'first_air_date' => '1999-01-01',
        'overview' => 'A synthetic series used to verify generic edition-title matching.',
    ];
    $genericClassicTmdb = new CandidateTmdbService(
        tvDetails: [701 => $genericClassicDetails],
        tvCandidatesByQuery: [
            'Beacon Vale Classics (12)' => [],
            'Beacon Vale' => [$genericClassicCandidate],
        ],
        movieCandidatesByQuery: [
            'Beacon Vale Classics (12)' => [],
            'Beacon Vale' => [],
        ],
    );
    $genericClassicProgramme = ['title' => 'Beacon Vale Classics (12)', 'episode_num' => '0.11'];
    $genericClassicCache = [];
    enrich($plugin, $method, $genericClassicProgramme, $genericClassicTmdb, $genericClassicCache);
    assertSameValue(
        'https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg',
        $genericClassicProgramme['icon'] ?? null,
        'Any episodic Classics title should retry the generic base series and select a validated TMDB candidate.'
    );
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->tvQueries, 'Generic edition matching must search the full TV title before the derived base title.');
    assertSameValue([], $genericClassicTmdb->movieQueries, 'Exact episode structure must keep generic edition matching on TV.');
    assertSameValue([1, 0], [$genericClassicTmdb->tvDetailsRequests, $genericClassicTmdb->movieDetailsRequests], 'Only the globally validated generic edition winner should load details.');

    $genericSeriesTmdb = new CandidateTmdbService(
        tvCandidatesByQuery: ['Beacon Vale Classics' => []],
        movieCandidatesByQuery: ['Beacon Vale Classics' => []],
    );
    $genericSeriesProgramme = ['title' => 'Beacon Vale Classics'];
    $genericSeriesCache = [];
    enrich($plugin, $method, $genericSeriesProgramme, $genericSeriesTmdb, $genericSeriesCache);
    assertSameValue(null, $genericSeriesProgramme['icon'] ?? null, 'A plain Classics title without episode evidence must abstain when the full title has no validated candidate.');
    assertSameValue([], $genericSeriesTmdb->tvQueries, 'A title-only Classics row must remain UNKNOWN without a TV search.');
    assertSameValue([], $genericSeriesTmdb->movieQueries, 'A title-only Classics row must remain UNKNOWN without a movie search.');
    assertSameValue([0, 0], [$genericSeriesTmdb->tvDetailsRequests, $genericSeriesTmdb->movieDetailsRequests], 'An abstaining plain Classics title must not load details.');

    $genericSeriesReplay = ['title' => 'Beacon Vale Classics (12)', 'episode_num' => '0.11'];
    enrich($plugin, $method, $genericSeriesReplay, $genericClassicTmdb, $genericClassicCache);
    assertSameValue($genericClassicProgramme, $genericSeriesReplay, 'A repeated globally matched episodic title should replay the same trusted output.');
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->tvQueries, 'A validated generic cache hit must not repeat TV candidate searches.');
    assertSameValue([], $genericClassicTmdb->movieQueries, 'A validated exact-episode cache hit must not search movie candidates.');
    assertSameValue([1, 0], [$genericClassicTmdb->tvDetailsRequests, $genericClassicTmdb->movieDetailsRequests], 'A validated generic cache hit must not repeat details requests.');

    foreach ([
        'Beacon Vale Classics - Folge 1',
        'Beacon Vale Classics S01E01',
        'Beacon Vale Classics Folge 1',
        'Beacon Vale Classics (12)',
    ] as $episodeTitle) {
        $episodeTmdb = new CandidateTmdbService(
            tvDetails: [701 => $genericClassicDetails],
            tvCandidatesByQuery: [
                $episodeTitle => [],
                'Beacon Vale' => [$genericClassicCandidate],
            ],
            movieCandidatesByQuery: [
                $episodeTitle => [],
                'Beacon Vale' => [],
            ],
        );
        $episodeProgramme = ['title' => $episodeTitle, 'episode_num' => '0.0'];
        $episodeCache = [];
        enrich($plugin, $method, $episodeProgramme, $episodeTmdb, $episodeCache);
        assertSameValue('https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg', $episodeProgramme['icon'] ?? null, $episodeTitle.' should resolve through the same generic base-title pipeline.');
        assertSameValue([$episodeTitle, 'Beacon Vale'], $episodeTmdb->tvQueries, $episodeTitle.' should search the full TV title before the derived base title.');
        assertSameValue([], $episodeTmdb->movieQueries, $episodeTitle.' exact episode structure must not search movie candidates.');
        assertSameValue([1, 0], [$episodeTmdb->tvDetailsRequests, $episodeTmdb->movieDetailsRequests], $episodeTitle.' should load only the globally validated TV winner.');
    }

    $normalizedEditionTmdb = new CandidateTmdbService(
        tvDetails: [701 => $genericClassicDetails],
        tvCandidatesByQuery: [
            '  BEACON-VALE,   CLASSICS Folge 9  ' => [],
            'BEACON-VALE,' => [$genericClassicCandidate],
        ],
        movieCandidatesByQuery: [
            '  BEACON-VALE,   CLASSICS Folge 9  ' => [],
            'BEACON-VALE,' => [],
        ],
    );
    $normalizedEditionProgramme = ['title' => '  BEACON-VALE,   CLASSICS Folge 9  ', 'episode_num' => '0.8'];
    $normalizedEditionCache = [];
    enrich($plugin, $method, $normalizedEditionProgramme, $normalizedEditionTmdb, $normalizedEditionCache);
    assertSameValue('https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg', $normalizedEditionProgramme['icon'] ?? null, 'Case, whitespace, and punctuation variants should use generic normalized candidate matching.');
    assertSameValue(['  BEACON-VALE,   CLASSICS Folge 9  ', 'BEACON-VALE,'], $normalizedEditionTmdb->tvQueries, 'Normalized title variants must preserve the full-then-base TV query order.');
    assertSameValue([], $normalizedEditionTmdb->movieQueries, 'Exact episode evidence must keep punctuation-compatible matching on TV.');

    $globalMovieDetails = normalizedMovieDetailsFixture(
        702,
        'Kestrel Ridge - A Winter Chronicle',
        'A synthetic movie regression exercised through the global candidate pipeline.',
        'https://image.tmdb.org/t/p/w500/kestrel-ridge-poster.jpg',
        'https://image.tmdb.org/t/p/original/kestrel-ridge-backdrop.jpg',
    );
    $globalMovieTmdb = new CandidateTmdbService(
        movieCandidates: [[
            'tmdb_id' => 702,
            'title' => 'Kestrel Ridge: Summit of Ice',
            'original_title' => 'Kestrel Ridge: Summit of Ice',
            'release_date' => '2020-01-01',
            'overview' => 'A synthetic movie regression exercised through the global candidate pipeline.',
        ]],
        movieDetails: [702 => $globalMovieDetails],
    );
    $globalMovieProgramme = ['title' => 'Kestrel Ridge - A Winter Chronicle', 'date' => '2020'];
    $globalMovieCache = [];
    enrich($plugin, $method, $globalMovieProgramme, $globalMovieTmdb, $globalMovieCache);
    assertSameValue('https://image.tmdb.org/t/p/original/kestrel-ridge-backdrop.jpg', $globalMovieProgramme['icon'] ?? null, 'A unique compound-title variant with the same substantial base should resolve globally.');
    assertSameValue([2, 2], [$globalMovieTmdb->tvCandidateSearches, $globalMovieTmdb->movieCandidateSearches], 'A weak compound-title identity must be confirmed by full and base TV/movie searches.');
    assertSameValue(['Kestrel Ridge - A Winter Chronicle', 'Kestrel Ridge'], $globalMovieTmdb->tvQueries, 'Compound confirmation must search the full then base TV title.');
    assertSameValue(['Kestrel Ridge - A Winter Chronicle', 'Kestrel Ridge'], $globalMovieTmdb->movieQueries, 'Compound confirmation must search the full then base movie title.');
    assertSameValue([0, 1], [$globalMovieTmdb->tvDetailsRequests, $globalMovieTmdb->movieDetailsRequests], 'Only the global movie winner should load details.');

    $shortCompoundTmdb = new CandidateTmdbService(movieCandidates: [[
        'tmdb_id' => 704,
        'title' => 'All In - League Show',
        'original_title' => 'All In - League Show',
        'release_date' => '2024-01-01',
        'overview' => 'A different synthetic programme.',
    ]]);
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $shortCompoundTmdb, 'All In - Documentary'),
        'A short common compound base must not receive the substantial-base identity boost.'
    );

    $ambiguousCompoundTmdb = new CandidateTmdbService(movieCandidates: [
        [
            'tmdb_id' => 705,
            'title' => 'Kestrel Ridge: Summit of Ice',
            'original_title' => 'Kestrel Ridge: Summit of Ice',
            'release_date' => '2020-01-01',
            'overview' => 'First ambiguous synthetic candidate.',
        ],
        [
            'tmdb_id' => 706,
            'title' => 'Kestrel Ridge: Frozen Pass',
            'original_title' => 'Kestrel Ridge: Frozen Pass',
            'release_date' => '2020-01-01',
            'overview' => 'Second ambiguous synthetic candidate.',
        ],
    ]);
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $ambiguousCompoundTmdb, 'Kestrel Ridge - A Winter Chronicle'),
        'Equal compound-base candidates must remain ambiguous and fail closed.'
    );
    assertSameValue(0, $ambiguousCompoundTmdb->movieDetailsRequests, 'Ambiguous compound-base candidates must not load details.');

    $mismatchedCompoundTmdb = new CandidateTmdbService(
        movieDetails: [707 => normalizedMovieDetailsFixture(707, 'Kestrel Ridge: Summit of Ice', 'First candidate.')],
        movieCandidatesByQuery: [
            'Kestrel Ridge - A Winter Chronicle' => [[
                'tmdb_id' => 707,
                'title' => 'Kestrel Ridge: Summit of Ice',
                'original_title' => 'Kestrel Ridge: Summit of Ice',
                'release_date' => '2020-01-01',
                'overview' => 'First candidate.',
            ]],
            'Kestrel Ridge' => [[
                'tmdb_id' => 707,
                'title' => 'Kestrel Ridge',
                'original_title' => 'Kestrel Ridge',
                'release_date' => '2020-01-01',
                'overview' => 'Same identity but non-compound base response.',
            ]],
        ],
    );
    $mismatchedCompoundProgramme = ['title' => 'Kestrel Ridge - A Winter Chronicle', 'date' => '2020'];
    $mismatchedCompoundCache = [];
    enrich($plugin, $method, $mismatchedCompoundProgramme, $mismatchedCompoundTmdb, $mismatchedCompoundCache);
    assertSameValue(null, $mismatchedCompoundProgramme['icon'] ?? null, 'A base-only same-ID response must not bypass compound-shape confirmation.');
    assertSameValue(0, $mismatchedCompoundTmdb->movieDetailsRequests, 'A non-compound base confirmation must not load details.');

    $literalClassicsDetails = normalizedTvDetailsFixture(
        703,
        'Fictional Archive Classics',
        'An exact synthetic TMDB title whose final word is Classics.',
        backdropUrl: 'https://image.tmdb.org/t/p/original/fictional-archive-classics.jpg',
    );
    $literalClassicsTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 703,
            'name' => 'Fictional Archive Classics',
            'original_name' => 'Fictional Archive Classics',
            'first_air_date' => '2021-01-01',
            'overview' => 'An exact synthetic TMDB title whose final word is Classics.',
        ]],
        tvDetails: [703 => $literalClassicsDetails],
    );
    $literalClassicsProgramme = ['title' => 'Fictional Archive Classics', 'date' => '2021'];
    $literalClassicsCache = [];
    enrich($plugin, $method, $literalClassicsProgramme, $literalClassicsTmdb, $literalClassicsCache);
    assertSameValue('https://image.tmdb.org/t/p/original/fictional-archive-classics.jpg', $literalClassicsProgramme['icon'] ?? null, 'An exact TMDB title ending in Classics must win before the generic base fallback.');
    assertSameValue([1, 1], [$literalClassicsTmdb->tvCandidateSearches, $literalClassicsTmdb->movieCandidateSearches], 'An exact Classics title must stop after the full-title candidate lookup.');
    assertSameValue(['Fictional Archive Classics'], $literalClassicsTmdb->tvQueries, 'An exact Classics title must use only the full TV query.');
    assertSameValue(['Fictional Archive Classics'], $literalClassicsTmdb->movieQueries, 'An exact Classics title must use only the full movie query.');

    $rankedTmdb = new CandidateTmdbService(
        tvCandidates: [
            ['tmdb_id' => 601, 'name' => 'Unrelated Result', 'original_name' => 'Unrelated Result', 'first_air_date' => '2018-01-01', 'overview' => 'No shared evidence.'],
            ['tmdb_id' => 602, 'name' => 'Ranked Target', 'original_name' => 'Ranked Target', 'first_air_date' => '2024-01-01', 'overview' => 'The correct synthetic result.'],
        ],
        movieCandidates: [
            ['tmdb_id' => 603, 'title' => 'Different Film', 'original_title' => 'Different Film', 'release_date' => '2024-01-01', 'overview' => 'No shared evidence.'],
        ],
        tvDetails: [602 => normalizedTvDetailsFixture(
            602,
            'Ranked Target',
            'The correct synthetic result.',
            backdropUrl: 'https://image.tmdb.org/t/p/original/ranked-target.jpg',
        )],
    );
    $rankedWinner = validatedSearch($plugin, $searchMethod, $rankedTmdb, 'Ranked Target', null, 2024);
    assertSameValue(602, $rankedWinner['tmdb_id'] ?? null, 'A correct candidate behind rank one should win global identity validation.');
    assertSameValue([5], $rankedTmdb->tvLimits, 'TV candidate lookup should request at most five results.');
    assertSameValue([5], $rankedTmdb->movieLimits, 'Movie candidate lookup should request at most five results.');
    assertSameValue(1, $rankedTmdb->tvDetailsRequests, 'Only the validated TV winner should load details.');
    assertSameValue(0, $rankedTmdb->movieDetailsRequests, 'Losing movie candidates must not load details.');
    assertSameValue(0, $rankedTmdb->tvAlternativeRequests + $rankedTmdb->movieAlternativeRequests, 'Candidate validation must not load alternative titles.');

    $globalTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 611,
            'name' => 'Global Choice',
            'original_name' => 'Global Choice',
            'first_air_date' => '2010-01-01',
            'overview' => 'An older synthetic series.',
        ]],
        movieCandidates: [[
            'tmdb_id' => 612,
            'title' => 'Global Choice',
            'original_title' => 'Global Choice',
            'release_date' => '2024-01-01',
            'overview' => 'The matching synthetic movie.',
        ]],
        movieDetails: [612 => normalizedMovieDetailsFixture(
            612,
            'Global Choice',
            'The matching synthetic movie.',
            backdropUrl: 'https://image.tmdb.org/t/p/original/global-movie.jpg',
        )],
    );
    $globalWinner = validatedSearch($plugin, $searchMethod, $globalTmdb, 'Global Choice', null, 2024);
    assertSameValue([612, 'movie'], [$globalWinner['tmdb_id'] ?? null, $globalWinner['_media_type'] ?? null], 'TV and movie candidates should compete in one global ranking.');
    assertSameValue([0, 1], [$globalTmdb->tvDetailsRequests, $globalTmdb->movieDetailsRequests], 'Only the global movie winner should load details.');

    $forcedTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 621,
            'name' => 'Forced Series',
            'original_name' => 'Forced Series',
            'first_air_date' => '2022-01-01',
            'overview' => 'A synthetic episodic series.',
        ]],
        movieCandidates: [[
            'tmdb_id' => 622,
            'title' => 'Forced Series',
            'original_title' => 'Forced Series',
            'release_date' => '2022-01-01',
            'overview' => 'A synthetic movie.',
        ]],
        tvDetails: [621 => normalizedTvDetailsFixture(
            621,
            'Forced Series',
            'A synthetic episodic series.',
        )],
    );
    $forcedWinner = validatedSearch($plugin, $searchMethod, $forcedTmdb, 'Forced Series', 'tv', 2022);
    assertSameValue(621, $forcedWinner['tmdb_id'] ?? null, 'Forced TV validation should select from TV candidates.');
    assertSameValue([1, 0], [$forcedTmdb->tvCandidateSearches, $forcedTmdb->movieCandidateSearches], 'Forced TV validation must not request movie candidates.');

    foreach ([
        'mismatched native ID' => normalizedTvDetailsFixture(
            702,
            'Details Identity',
            'The selected bounded candidate.',
            backdropUrl: 'https://image.tmdb.org/t/p/original/wrong-id.jpg',
        ),
        'mismatched media type' => array_merge(
            normalizedTvDetailsFixture(
                701,
                'Details Identity',
                'The selected bounded candidate.',
                backdropUrl: 'https://image.tmdb.org/t/p/original/wrong-type.jpg',
            ),
            ['_media_type' => 'movie']
        ),
    ] as $label => $invalidWinnerDetails) {
        $invalidWinnerTmdb = new CandidateTmdbService(
            tvCandidates: [[
                'tmdb_id' => 701,
                'name' => 'Details Identity',
                'original_name' => 'Details Identity',
                'first_air_date' => '2024-01-01',
                'overview' => 'The selected bounded candidate.',
            ]],
            tvDetails: [701 => $invalidWinnerDetails],
        );
        assertSameValue(
            null,
            validatedSearch($plugin, $searchMethod, $invalidWinnerTmdb, 'Details Identity', 'tv', 2024),
            'Bounded candidate '.$label.' details must fail closed.'
        );
        assertSameValue(1, $invalidWinnerTmdb->tvDetailsRequests, 'Bounded candidate '.$label.' must make exactly one details request.');
    }

    $cacheGuardService = static fn (bool $throwOnCandidates = false): CandidateTmdbService => new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 711,
            'name' => 'Cache Guard Series',
            'original_name' => 'Cache Guard Series',
            'first_air_date' => '2024-01-01',
            'overview' => 'A bounded cache guard series.',
        ]],
        tvDetails: [711 => normalizedTvDetailsFixture(
            711,
            'Cache Guard Series',
            'A bounded cache guard series.',
            backdropUrl: 'https://image.tmdb.org/t/p/original/cache-guard.jpg',
        )],
        throwOnTvCandidates: $throwOnCandidates,
    );
    $cacheGuardProgramme = ['title' => 'Cache Guard Series', 'episode_num' => '0.0'];
    $cacheGuardSeed = $cacheGuardProgramme;
    $cacheGuardCache = [];
    enrich($plugin, $method, $cacheGuardSeed, $cacheGuardService(), $cacheGuardCache);
    $cacheGuardKey = array_key_first($cacheGuardCache);
    assertSameValue(true, is_string($cacheGuardKey), 'A valid bounded lookup should persist its full cache key.');

    $maliciousCacheEntry = array_merge(
        normalizedTvDetailsFixture(
            777,
            'Cache Guard Series',
            'A poisoned persisted cache entry.',
            backdropUrl: 'https://attacker.invalid/cache-poison.jpg',
        ),
        ['_media_type' => 'tv']
    );
    $cacheGuardCache[$cacheGuardKey] = $maliciousCacheEntry;
    $cacheGuardReplay = $cacheGuardProgramme;
    $cacheGuardRepairTmdb = $cacheGuardService();
    $cacheGuardRepairResult = enrich($plugin, $method, $cacheGuardReplay, $cacheGuardRepairTmdb, $cacheGuardCache);
    assertSameValue(false, $cacheGuardRepairResult['cache_hit'] ?? null, 'Malformed generic cache data must not count as a cache hit.');
    assertSameValue('https://image.tmdb.org/t/p/original/cache-guard.jpg', $cacheGuardReplay['icon'] ?? null, 'Malformed generic cache data must reload the bounded validated identity.');
    assertSameValue([1, 0], [$cacheGuardRepairTmdb->tvCandidateSearches, $cacheGuardRepairTmdb->movieCandidateSearches], 'Malformed exact-episode cache data must perform one bounded TV lookup and no movie lookup.');
    assertSameValue(711, $cacheGuardCache[$cacheGuardKey]['tmdb_id'] ?? null, 'A repaired generic cache entry must persist the validated native identity.');

    $cacheGuardCache[$cacheGuardKey] = $maliciousCacheEntry;
    $cacheGuardFailure = $cacheGuardProgramme;
    $cacheGuardFailureBefore = $cacheGuardFailure;
    enrich($plugin, $method, $cacheGuardFailure, $cacheGuardService(true), $cacheGuardCache);
    $cacheGuardFailureComparable = $cacheGuardFailure;
    unset($cacheGuardFailureComparable['tmdb_decision']);
    assertSameValue($cacheGuardFailureBefore, $cacheGuardFailureComparable, 'Malformed generic cache data must fail closed when bounded reload fails.');
    assertSameValue('unmatched', $cacheGuardFailure['tmdb_decision']['result'] ?? null, 'A bounded reload failure must retain unmatched decision evidence.');
    assertSameValue(false, array_key_exists($cacheGuardKey, $cacheGuardCache), 'Malformed generic cache data must not remain persisted after bounded reload fails.');

    $persistedCache = [
        '__language' => 'de-DE',
        'full' => $maliciousCacheEntry,
        'base' => array_merge($maliciousCacheEntry, ['_runtime_trusted_legacy' => true]),
        'series' => ['_media_type' => 'movie', 'tmdb_id' => 0],
        'valid' => array_merge(
            normalizedTvDetailsFixture(711, 'Cache Guard Series', 'A bounded cache guard series.'),
            ['_media_type' => 'tv']
        ),
        'unsafe_provenance' => array_merge(
            normalizedTvDetailsFixture(711, 'Cache Guard Series', 'A bounded cache guard series.'),
            [
                '_media_type' => 'tv',
                '_match_evidence' => [
                    'reason' => 'selected',
                    'score' => 92,
                    'margin' => 20,
                    'media_type' => 'tv',
                    'selected_title_provenance' => [
                        'source' => 'translation',
                        'language' => 'https://secret.invalid/token',
                        'region' => 'US',
                    ],
                ],
            ],
        ),
    ];
    $sanitizeTmdbCacheMethod->invokeArgs($plugin, [&$persistedCache]);
    assertSameValue(
        ['__language', 'valid'],
        array_keys($persistedCache),
        'Persisted full, base, and series cache entries must all use the exact normalized schema.'
    );

    $thresholdTmdb = new CandidateTmdbService(tvCandidates: [[
        'tmdb_id' => 631,
        'name' => 'Unrelated Name',
        'original_name' => 'Unrelated Name',
        'first_air_date' => '2024-01-01',
        'overview' => 'No useful evidence.',
    ]]);
    assertSameValue(null, validatedSearch($plugin, $searchMethod, $thresholdTmdb, 'Threshold Target', 'tv', 2024), 'A candidate below identity threshold should abstain.');
    assertSameValue(0, $thresholdTmdb->tvDetailsRequests, 'Threshold misses must not load details.');

    $titlePrefixDetails = normalizedTvDetailsFixture(
        632,
        'Signal Patrol!',
        'Inspectors solve routine problems for local residents.',
        backdropUrl: 'https://image.tmdb.org/t/p/original/signal-patrol-backdrop.jpg',
    );
    $titlePrefixCandidate = [
        'tmdb_id' => 632,
        'name' => 'Signal Patrol!',
        'original_name' => 'Signal Patrol!',
        'first_air_date' => '2008-06-02',
        'overview' => 'Inspectors solve routine problems for local residents.',
    ];
    $titlePrefixTmdb = new CandidateTmdbService(
        tvCandidates: [$titlePrefixCandidate],
        tvDetails: [632 => $titlePrefixDetails],
    );
    assertSameValue(
        632,
        validatedSearch(
            $plugin,
            $searchMethod,
            $titlePrefixTmdb,
            'Signal Patrol! We Take Care Of It',
            'tv',
            null,
            'Inspectors solve routine problems for local residents.',
        )['tmdb_id'] ?? null,
        'A longer programme title should accept a complete TMDB title prefix only with corroborating description evidence.'
    );
    assertSameValue(1, $titlePrefixTmdb->tvDetailsRequests, 'A corroborated complete title prefix should load its validated TV details.');

    $punctuatedPrefixWithoutDescriptionTmdb = new CandidateTmdbService(
        tvCandidates: [$titlePrefixCandidate],
        tvDetails: [632 => $titlePrefixDetails],
    );
    assertSameValue(
        632,
        validatedSearch($plugin, $searchMethod, $punctuatedPrefixWithoutDescriptionTmdb, 'Signal Patrol! We Take Care Of It', 'tv')['tmdb_id'] ?? null,
        'A complete multi-word TV title ending in punctuation should remain strong identity evidence without description overlap.'
    );
    assertSameValue(1, $punctuatedPrefixWithoutDescriptionTmdb->tvDetailsRequests, 'A punctuated complete-title prefix should load the validated TV details exactly once.');

    $plainWordPrefixCandidate = $titlePrefixCandidate;
    $plainWordPrefixCandidate['name'] = 'Signal Patrol';
    $plainWordPrefixCandidate['original_name'] = 'Signal Patrol';
    $plainWordPrefixTmdb = new CandidateTmdbService(
        tvCandidates: [$plainWordPrefixCandidate],
        tvDetails: [632 => $titlePrefixDetails],
    );
    assertSameValue(
        null,
        validatedSearch(
            $plugin,
            $searchMethod,
            $plainWordPrefixTmdb,
            'Signal Patrol Stories',
            'tv',
            null,
            'Inspectors solve routine problems for local residents.',
        ),
        'A plain word prefix must not use the complete-title-prefix exception even with description overlap.'
    );
    assertSameValue(0, $plainWordPrefixTmdb->tvDetailsRequests, 'A plain word prefix must not load details.');

    $nearPrefixTmdb = new CandidateTmdbService(tvCandidates: [$titlePrefixCandidate]);
    assertSameValue(
        null,
        validatedSearch(
            $plugin,
            $searchMethod,
            $nearPrefixTmdb,
            'Signal Patrols! We Take Care Of It',
            'tv',
            null,
            'Inspectors solve routine problems for local residents.',
        ),
        'A near-prefix must not use the complete-title-prefix exception.'
    );
    assertSameValue(0, $nearPrefixTmdb->tvDetailsRequests, 'A near-prefix must not load details.');

    $ambiguousSilenceTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 633,
            'name' => 'The Silence',
            'original_name' => 'The Silence',
            'first_air_date' => '2019-01-01',
            'overview' => 'A synthetic television result.',
        ]],
        movieCandidates: [[
            'tmdb_id' => 634,
            'title' => 'The Silence',
            'original_title' => 'The Silence',
            'release_date' => '2019-01-01',
            'overview' => 'A synthetic movie result.',
        ]],
    );
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $ambiguousSilenceTmdb, 'The Silence'),
        'An ambiguous title without year, media-type, or corroborating description evidence must fail closed.'
    );
    assertSameValue([0, 0], [$ambiguousSilenceTmdb->tvDetailsRequests, $ambiguousSilenceTmdb->movieDetailsRequests], 'An ambiguous title must not load candidate details.');

    $marginTmdb = new CandidateTmdbService(tvCandidates: [
        ['tmdb_id' => 641, 'name' => 'Margin Target', 'original_name' => 'Margin Target', 'first_air_date' => '2024-01-01', 'overview' => 'No overlap.'],
        ['tmdb_id' => 642, 'name' => 'Margin Target', 'original_name' => 'Margin Target', 'first_air_date' => '2024-01-01', 'overview' => 'Shared alpha evidence.'],
    ]);
    assertSameValue(null, validatedSearch($plugin, $searchMethod, $marginTmdb, 'Margin Target', 'tv', 2024, 'Shared alpha description.'), 'An insufficient winner margin should abstain.');
    assertSameValue(0, $marginTmdb->tvDetailsRequests, 'Margin abstention must not load candidate details.');

    $malformedTmdb = new CandidateTmdbService(tvCandidates: [
        ['tmdb_id' => 651, 'name' => 'Malformed Target', 'original_name' => 'Malformed Target', 'first_air_date' => '2024-01-01', 'overview' => 'Valid candidate.'],
        ['name' => 'Missing Identity'],
    ]);
    assertSameValue(null, validatedSearch($plugin, $searchMethod, $malformedTmdb, 'Malformed Target', 'tv', 2024), 'A malformed raw candidate should make validation abstain safely.');
    assertSameValue(0, $malformedTmdb->tvDetailsRequests, 'Malformed candidate abstention must not load details.');

    $invalidRawCandidateIds = [
        'positive integral float' => 123.0,
        'fractional float' => 1.5,
        'fractional numeric string' => '1.5',
        'exponent numeric string' => '1e3',
        'integer numeric string' => '123',
        'null' => null,
        'true' => true,
        'false' => false,
        'array' => [],
        'object' => (object) ['id' => 123],
        'zero' => 0,
        'negative integer' => -123,
    ];
    foreach ($invalidRawCandidateIds as $label => $invalidId) {
        $invalidIdTmdb = new CandidateTmdbService(
            tvCandidates: [[
                'tmdb_id' => $invalidId,
                'name' => 'Invalid Identity',
                'original_name' => 'Invalid Identity',
                'first_air_date' => '2024-01-01',
                'overview' => 'A candidate with an invalid raw identity.',
            ]],
            tvDetails: [
                1 => ['backdrop_url' => 'https://fixture.invalid/invalid-1.jpg'],
                123 => ['backdrop_url' => 'https://fixture.invalid/invalid-123.jpg'],
                1000 => ['backdrop_url' => 'https://fixture.invalid/invalid-1000.jpg'],
            ],
        );
        $invalidIdProgramme = ['title' => 'Invalid Identity', 'episode_num' => '0.0'];
        $invalidIdBefore = $invalidIdProgramme;
        $invalidIdCache = [];

        enrich($plugin, $method, $invalidIdProgramme, $invalidIdTmdb, $invalidIdCache);

        assertSameValue(0, $invalidIdTmdb->tvDetailsRequests, ucfirst($label).' raw candidate ID must not trigger a details request.');
        $invalidIdComparable = $invalidIdProgramme;
        unset($invalidIdComparable['tmdb_decision']);
        assertSameValue($invalidIdBefore, $invalidIdComparable, ucfirst($label).' raw candidate ID must not modify programme data.');
        assertSameValue('unmatched', $invalidIdProgramme['tmdb_decision']['result'] ?? null, ucfirst($label).' raw candidate ID must retain unmatched evidence.');
        assertSameValue([], $invalidIdCache, ucfirst($label).' raw candidate ID must not write a TMDB cache entry.');
    }

    $errorTmdb = new CandidateTmdbService(throwOnTvCandidates: true);
    assertSameValue(null, validatedSearch($plugin, $searchMethod, $errorTmdb, 'Error Target', 'tv', 2024), 'A candidate-method error should abstain without escaping.');

    $missingDetailsCandidates = [[
        'tmdb_id' => 671,
        'name' => 'Missing Details Identity',
        'original_name' => 'Missing Details Identity',
        'first_air_date' => '2024-01-01',
        'overview' => 'A synthetic identity whose details are unavailable.',
    ]];
    $missingDetailsTmdb = new CandidateTmdbService(tvCandidates: $missingDetailsCandidates);
    assertSameValue(
        null,
        validatedSearch($plugin, $searchMethod, $missingDetailsTmdb, 'Missing Details Identity', 'tv', 2024),
        'A validated bounded-candidate winner with missing details should fail closed.'
    );
    assertSameValue(1, $missingDetailsTmdb->tvDetailsRequests, 'The validated winner should make exactly one details request.');

    $missingDetailsEnrichmentTmdb = new CandidateTmdbService(tvCandidates: $missingDetailsCandidates);
    $missingDetailsProgramme = ['title' => 'Missing Details Identity', 'date' => '2024'];
    $missingDetailsBefore = $missingDetailsProgramme;
    $missingDetailsCache = [];
    enrich($plugin, $method, $missingDetailsProgramme, $missingDetailsEnrichmentTmdb, $missingDetailsCache);
    $missingDetailsComparable = $missingDetailsProgramme;
    unset($missingDetailsComparable['tmdb_decision']);
    assertSameValue($missingDetailsBefore, $missingDetailsComparable, 'Missing winner details should not modify programme data.');
    assertSameValue('unmatched', $missingDetailsProgramme['tmdb_decision']['result'] ?? null, 'Missing winner details must retain unmatched decision evidence.');
    assertSameValue([], $missingDetailsCache, 'Missing winner details should not write a TMDB cache entry.');
    assertSameValue(1, $missingDetailsEnrichmentTmdb->tvDetailsRequests, 'Normal enrichment should request only the validated winner details.');

    $legacyTmdb = new TmdbService('long-walk');
    $legacyWinner = validatedSearch(
        $plugin,
        $searchMethod,
        $legacyTmdb,
        'The Long Walk',
        null,
        2025,
        'Bei einem Todesmarsch muss eine Gruppe junger Männer immer weitergehen.',
    );
    assertSameValue([200, 'movie'], [$legacyWinner['tmdb_id'] ?? null, $legacyWinner['_media_type'] ?? null], 'An older host may select only when its TV/movie results provide the required measurable margin.');

    $abstentionTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 661,
            'name' => 'Balanced Identity',
            'original_name' => 'Balanced Identity',
            'first_air_date' => '',
            'overview' => 'A synthetic TV identity.',
        ]],
        movieCandidates: [[
            'tmdb_id' => 662,
            'title' => 'Balanced Identity',
            'original_title' => 'Balanced Identity',
            'release_date' => '',
            'overview' => 'A synthetic movie identity.',
        ]],
    );
    $abstentionProgramme = ['title' => 'Balanced Identity', 'date' => '2024'];
    $abstentionBefore = $abstentionProgramme;
    $abstentionCache = [];
    enrich($plugin, $method, $abstentionProgramme, $abstentionTmdb, $abstentionCache);
    $abstentionComparable = $abstentionProgramme;
    unset($abstentionComparable['tmdb_decision']);
    assertSameValue($abstentionBefore, $abstentionComparable, 'Identity abstention should not modify programme data.');
    assertSameValue('ambiguous_identity', $abstentionProgramme['tmdb_decision']['class'] ?? null, 'A global tie must retain ambiguous identity evidence.');
    assertSameValue([], $abstentionCache, 'Identity abstention should not write a TMDB cache entry.');
    assertSameValue(0, $abstentionTmdb->tvDetailsRequests + $abstentionTmdb->movieDetailsRequests, 'A global tie must not load any details.');

    $longWalk = [
        'title' => 'The Long Walk - Der Todesmarsch',
        'date' => '2025',
        'desc' => 'USA 2025. Bei einem Todesmarsch darf niemand stehen bleiben.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/unknown.jpg',
        'images' => [
            ['url' => 'https://provider.invalid/unknown.jpg'],
            ['url' => 'https://provider.invalid/unknown.jpg'],
            ['url' => 'https://fixture.invalid/movie-backdrop.jpg', 'type' => 'backdrop', 'orient' => 'L', 'width' => 4000, 'height' => 2250],
            ['url' => 'https://provider.invalid/logo.png', 'type' => 'logo', 'orient' => 'L', 'width' => 4000, 'height' => 800],
        ],
    ];
    $longWalkCache = [];
    $longWalkTmdb = new TmdbService('long-walk');
    $longWalkResult = enrich($plugin, $method, $longWalk, $longWalkTmdb, $longWalkCache);

    assertSameValue('https://fixture.invalid/movie-backdrop.jpg', $longWalk['icon'], 'Movie backdrop should repair an untrusted provider icon.');
    assertSameValue(
        [
            'https://fixture.invalid/movie-backdrop.jpg',
            'https://fixture.invalid/movie-poster.jpg',
            'https://provider.invalid/unknown.jpg',
            'https://provider.invalid/logo.png',
            'https://fixture.invalid/movie-backdrop.jpg',
        ],
        array_column($longWalk['images'], 'url'),
        'A movie primary should bracket secondary artwork for first- and last-icon consumers.'
    );
    assertSameValue('backdrop', $longWalk['images'][0]['type'], 'Primary image should be a landscape backdrop.');
    assertSameValue('tmdb', $longWalk['images'][0]['source'] ?? null, 'TMDB provenance should win deduplication over an unprovenanced record with the same URL and role.');
    assertSameValue($longWalk['icon'], $longWalk['images'][array_key_last($longWalk['images'])]['url'], 'The terminal movie image should duplicate the selected primary URL.');
    assertSameValue('backdrop', $longWalk['images'][array_key_last($longWalk['images'])]['type'], 'The terminal movie primary duplicate should retain its image type.');
    assertTrueValue(in_array('https://fixture.invalid/movie-poster.jpg', array_column($longWalk['images'], 'url'), true), 'Portrait poster should remain in images.');
    assertTrueValue($longWalkResult['changed'], 'Artwork repair should report a changed programme.');

    $stableLongWalk = json_encode($longWalk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    enrich($plugin, $method, $longWalk, $longWalkTmdb, $longWalkCache);
    assertSameValue(
        $stableLongWalk,
        json_encode($longWalk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'A second enrichment pass over plugin-created records should be byte-for-byte stable.'
    );

    foreach ([['portrait', 500, 750], ['square', 750, 750]] as [$geometry, $width, $height]) {
        $conflictingArtwork = [
            'title' => 'The Long Walk - Der Todesmarsch',
            'date' => '2025',
            'desc' => 'USA 2025. Bei einem Todesmarsch darf niemand stehen bleiben.',
            'category' => 'Movie',
            'icon' => 'https://provider.invalid/'.$geometry.'-fanart.jpg',
            'images' => [
                [
                    'url' => 'https://provider.invalid/'.$geometry.'-fanart.jpg',
                    'type' => 'fanart',
                    'orient' => 'L',
                    'width' => $width,
                    'height' => $height,
                ],
            ],
        ];
        $conflictingCache = [];
        $conflictingTmdb = new TmdbService('long-walk');
        enrich($plugin, $method, $conflictingArtwork, $conflictingTmdb, $conflictingCache);

        assertSameValue(
            'https://fixture.invalid/movie-backdrop.jpg',
            $conflictingArtwork['icon'],
            ucfirst($geometry).' fanart with conflicting geometry should be repaired by the TMDB backdrop.'
        );
        assertTrueValue(
            $conflictingTmdb->tvSearches + $conflictingTmdb->movieSearches > 0,
            ucfirst($geometry).' conflicting geometry should not retain the no-op fast path.'
        );
    }

    $illuminatiCache = [];
    $illuminatiTmdb = new TmdbService('illuminati');
    $illuminati = [
        'title' => 'Illuminati',
        'date' => '2009',
        'desc' => 'Thriller 2009 von Ron Howard mit Tom Hanks, Ewan McGregor und Ayelet Zurer.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/illuminati-unknown.jpg',
    ];
    enrich($plugin, $method, $illuminati, $illuminatiTmdb, $illuminatiCache);
    assertSameValue('https://fixture.invalid/illuminati-backdrop.jpg', $illuminati['icon'], 'Strong German alternative-title evidence should match Angels & Demons.');

    $weakIlluminati = [
        'title' => 'Illuminati',
        'date' => '2009',
        'desc' => 'Mystery thriller from 2009.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/weak-unknown.jpg',
    ];
    enrich($plugin, $method, $weakIlluminati, $illuminatiTmdb, $illuminatiCache);
    assertSameValue('https://fixture.invalid/illuminati-backdrop.jpg', $weakIlluminati['icon'], 'A tagged alternative title may use exact release year as independent evidence without description overlap.');
    assertSameValue('selected', $weakIlluminati['tmdb_decision']['result'] ?? null, 'Year-corroborated alternative-title selection must retain selected evidence.');
    assertSameValue(2, count($illuminatiCache), 'Distinct successful description-sensitive identities should remain isolated in cache.');

    $identityMovieService = static fn (int $id, string $title): CandidateTmdbService => new CandidateTmdbService(
        movieCandidates: [['tmdb_id' => $id, 'title' => $title, 'original_title' => $title, 'original_language' => 'en', 'release_date' => '', 'overview' => '']],
        movieDetails: [$id => normalizedMovieDetailsFixture($id, $title, backdropUrl: 'https://image.tmdb.org/t/p/original/identity-movie-'.$id.'.jpg')],
    );
    $identityTvService = static fn (int $id, string $title): CandidateTmdbService => new CandidateTmdbService(
        tvCandidates: [['tmdb_id' => $id, 'name' => $title, 'original_name' => $title, 'original_language' => 'en', 'first_air_date' => '', 'overview' => '']],
        tvDetails: [$id => normalizedTvDetailsFixture($id, $title, backdropUrl: 'https://image.tmdb.org/t/p/original/identity-tv-'.$id.'.jpg')],
    );
    $sourceCache = [];
    $sourceA = ['title' => 'Global Identity', 'date' => '2024'];
    enrich($plugin, $method, $sourceA, $identityMovieService(206, 'Global Identity'), $sourceCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $sourceB = ['title' => 'Global Identity', 'date' => '2024'];
    $sourceBResult = enrich($plugin, $method, $sourceB, $identityMovieService(207, 'Global Identity'), $sourceCache, [
        'epg_source_id' => 'source-b',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://image.tmdb.org/t/p/original/identity-movie-207.jpg', $sourceB['icon'] ?? null, 'Same-title records without year or description should be isolated by EPG source.');
    assertSameValue(true, $sourceBResult['lookup'], 'A different EPG source should perform its own TMDB lookup.');

    $languageCache = [];
    $english = ['title' => 'Language Identity - Episode One', 'episode_num' => '0.0'];
    enrich($plugin, $method, $english, $identityTvService(109, 'Language Identity - Episode One'), $languageCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $german = ['title' => 'Language Identity - Episode Two', 'episode_num' => '0.1'];
    $germanResult = enrich($plugin, $method, $german, $identityTvService(110, 'Language Identity - Episode Two'), $languageCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'de-DE',
    ]);
    assertSameValue('https://image.tmdb.org/t/p/original/identity-tv-110.jpg', $german['icon'] ?? null, 'Base-series records should be isolated by effective TMDB language.');
    assertSameValue(true, $germanResult['lookup'], 'A different TMDB language should perform its own lookup.');

    $tmdbIdentityCache = [];
    $tmdbIdentityA = ['title' => 'Assigned Identity - Episode One', 'episode_num' => '0.0', 'tmdb_id' => 109, 'tmdb_media_type' => 'tv'];
    enrich($plugin, $method, $tmdbIdentityA, $identityTvService(109, 'Assigned Identity - Episode One'), $tmdbIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $tmdbIdentityB = ['title' => 'Assigned Identity - Episode Two', 'episode_num' => '0.1', 'tmdb_id' => 110, 'tmdb_media_type' => 'tv'];
    $tmdbIdentityBResult = enrich($plugin, $method, $tmdbIdentityB, $identityTvService(110, 'Assigned Identity - Episode Two'), $tmdbIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://image.tmdb.org/t/p/original/identity-tv-110.jpg', $tmdbIdentityB['icon'] ?? null, 'Existing TMDB identities should keep base-series cache entries distinct.');
    assertSameValue(true, $tmdbIdentityBResult['lookup'], 'A different existing TMDB identity should perform its own lookup.');

    $episodeIdentityCache = [];
    $episodeA = ['title' => 'Episode Identity', 'episode_num' => '0.0', 'tmdb_id' => 109, 'tmdb_media_type' => 'tv'];
    enrich($plugin, $method, $episodeA, $identityTvService(109, 'Episode Identity'), $episodeIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $episodeB = ['title' => 'Episode Identity', 'episode_num' => '1.1', 'tmdb_id' => 110, 'tmdb_media_type' => 'tv'];
    $episodeBResult = enrich($plugin, $method, $episodeB, $identityTvService(110, 'Episode Identity'), $episodeIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://image.tmdb.org/t/p/original/identity-tv-110.jpg', $episodeB['icon'] ?? null, 'Unrelated season and episode identities should not share a full-title cache entry.');
    assertSameValue(true, $episodeBResult['lookup'], 'A different season and episode identity should perform its own lookup.');

    $mediaTypeCache = [];
    $movieIdentity = ['title' => 'Media Identity', 'date' => '2024', 'tmdb_id' => 206, 'tmdb_media_type' => 'movie'];
    enrich($plugin, $method, $movieIdentity, $identityMovieService(206, 'Media Identity'), $mediaTypeCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $tvIdentity = ['title' => 'Media Identity', 'episode_num' => '0.0', 'tmdb_id' => 110, 'tmdb_media_type' => 'tv'];
    $tvIdentityResult = enrich($plugin, $method, $tvIdentity, $identityTvService(110, 'Media Identity'), $mediaTypeCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://image.tmdb.org/t/p/original/identity-tv-110.jpg', $tvIdentity['icon'] ?? null, 'Movie and TV preferences should not share a cache entry.');
    assertSameValue(true, $tvIdentityResult['lookup'], 'A different media preference should perform its own lookup.');

    $bares = [
        'title' => 'Bares für Rares',
        'tmdb_id' => 102,
        'tmdb_media_type' => 'tv',
        'subtitle' => 'Ein außergewöhnliches Fundstück',
        'episode_num' => '0.0',
        'desc' => 'Horst Lichter begrüßt Menschen, die seltene Fundstücke und Antiquitäten von Experten schätzen lassen.',
        'category' => 'Series',
        'icon' => 'https://provider.invalid/bares-unknown.jpg',
    ];
    $baresCache = [];
    $baresTmdb = new TmdbService('bares');
    enrich($plugin, $method, $bares, $baresTmdb, $baresCache);
    assertSameValue('https://image.tmdb.org/t/p/original/bares-backdrop.jpg', $bares['icon'], 'Episodic signals should force the TV landscape backdrop.');
    assertTrueValue(in_array('https://image.tmdb.org/t/p/w500/bares-poster.jpg', array_column($bares['images'], 'url'), true), 'TV portrait poster should remain in images.');
    assertSameValue($bares['icon'], $bares['images'][0]['url'], 'The selected series landscape should remain the first image.');
    assertSameValue($bares['icon'], $bares['images'][array_key_last($bares['images'])]['url'], 'The selected series landscape should also be the final image.');
    assertSameValue('backdrop', $bares['images'][array_key_last($bares['images'])]['type'], 'The terminal series primary duplicate should retain its image type.');
    assertSameValue(0, $baresTmdb->tvSearches, 'The type-bound TV identity should resolve directly without a search.');
    assertSameValue(0, $baresTmdb->movieSearches, 'Strong episodic evidence should not search movies.');

    $ghostsCache = [];
    $ghostsTmdb = new TmdbService('ghosts');
    foreach ([
        ['Weihnachtsgeister', 'Die Bewohner bereiten Weihnachten vor.', '1.5'],
        ['Der Fahrgeist', 'Ein Ausflug bringt die Geister durcheinander.', '1.6'],
        ['Es bleibt in der Familie', 'Ein unerwarteter Besuch sorgt für Unruhe.', '1.7'],
    ] as $index => [$episodeTitle, $description, $episodeNum]) {
        $ghosts = [
            'title' => 'Ghosts - '.$episodeTitle,
            'tmdb_id' => 104,
            'tmdb_media_type' => 'tv',
            'desc' => $description,
            'episode_num' => $episodeNum,
            'category' => 'Series',
        ];
        $ghostsResult = enrich($plugin, $method, $ghosts, $ghostsTmdb, $ghostsCache);

        assertSameValue('https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg', $ghosts['icon'] ?? null, $episodeTitle.' should reuse the validated Ghosts series artwork.');
        assertSameValue($index === 0, $ghostsResult['lookup'], $episodeTitle.' should only search TMDB when validating the shared base series.');
        assertSameValue($index > 0, $ghostsResult['cache_hit'], $episodeTitle.' should report reuse of the validated base-series cache.');
    }
    assertSameValue(0, $ghostsTmdb->tvSearches, 'Type-bound Ghosts identities should resolve directly without title searches.');
    assertSameValue(0, $ghostsTmdb->movieSearches, 'Ghosts episode evidence should keep matching on TV.');

    $episodeStill = [
        'title' => 'Ghosts - Der Fahrgeist',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Ein Ausflug bringt die Geister durcheinander.',
        'episode_num' => '0.5.',
        'episode_nums' => [
            ['system' => 'xmltv_ns', 'value' => '0.5.'],
        ],
        'category' => 'Series',
        'icon' => 'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
        'images' => [
            [
                'url' => 'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
                'type' => 'backdrop',
                'orient' => 'L',
                'width' => 1920,
                'height' => 1080,
                'source' => 'tmdb',
                'scope' => 'series',
            ],
            [
                'url' => 'https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg',
                'type' => 'screenshot',
                'orient' => 'L',
                'width' => 1920,
                'height' => 1080,
            ],
        ],
    ];
    $episodeStillCache = [];
    $episodeSeasonCache = [];
    $episodeImagesCache = [];
    $episodeTmdb = new TmdbService('ghosts');
    enrich(
        $plugin,
        $method,
        $episodeStill,
        $episodeTmdb,
        $episodeStillCache,
        [],
        $episodeSeasonCache,
        $episodeImagesCache,
        true,
    );
    assertSameValue(
        'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
        $episodeStill['icon'] ?? null,
        'A trusted series backdrop should remain the programme icon when an exact episode still exists.'
    );
    assertSameValue(
        ['backdrop', 'screenshot', 'poster', 'backdrop'],
        array_column($episodeStill['images'] ?? [], 'type'),
        'The series backdrop should bracket typed episode and poster alternatives.'
    );
    $episodeStills = array_values(array_filter(
        $episodeStill['images'] ?? [],
        fn (array $image): bool => ($image['url'] ?? null) === 'https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg'
            && ($image['type'] ?? null) === 'screenshot'
    ));
    assertSameValue('tmdb', $episodeStills[0]['source'] ?? null, 'The exact episode still should retain its TMDB provenance as secondary artwork.');
    assertSameValue('episode', $episodeStills[0]['scope'] ?? null, 'The exact episode still should remain explicitly episode-scoped.');
    assertSameValue($episodeStill['icon'], $episodeStill['images'][array_key_last($episodeStill['images'])]['url'], 'The terminal image should duplicate the series-backdrop primary.');
    assertSameValue('backdrop', $episodeStill['images'][array_key_last($episodeStill['images'])]['type'], 'The terminal primary duplicate should retain its backdrop type.');

    $nextEpisodeStill = [
        'title' => 'Ghosts - Es bleibt in der Familie',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Ein unerwarteter Besuch sorgt für Unruhe.',
        'episode_num' => '0.6',
        'category' => 'Series',
    ];
    enrich(
        $plugin,
        $method,
        $nextEpisodeStill,
        $episodeTmdb,
        $episodeStillCache,
        [],
        $episodeSeasonCache,
        $episodeImagesCache,
        true,
    );
    assertSameValue('https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg', $nextEpisodeStill['icon'] ?? null, 'A second episode should keep the trusted series backdrop primary.');
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-s01e07.jpg', array_column($nextEpisodeStill['images'] ?? [], 'url'), true),
        'A second episode should retain its exact still as secondary artwork.'
    );
    assertSameValue(1, $episodeTmdb->seasonRequests, 'Episodes in one validated series season should safely reuse the season payload.');

    $trustedProviderEpisode = [
        'title' => 'Ghosts - Hau den Putz',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Die Geister versuchen, das Haus zu retten.',
        'episode_nums' => [
            ['system' => 'xmltv_ns', 'value' => '0.5.'],
        ],
        'category' => 'Series',
        'icon' => 'https://provider.invalid/ghosts-s01e06.jpg',
        'images' => [[
            'url' => 'https://provider.invalid/ghosts-s01e06.jpg',
            'type' => 'screenshot',
            'orient' => 'L',
            'width' => 1280,
            'height' => 720,
            'size' => 2,
            'source' => 'schedules_direct',
            'scope' => 'programme',
        ]],
    ];
    $trustedProviderCache = [];
    $trustedProviderSeasonCache = [];
    $trustedProviderImagesCache = [];
    enrich(
        $plugin,
        $method,
        $trustedProviderEpisode,
        new TmdbService('ghosts'),
        $trustedProviderCache,
        [],
        $trustedProviderSeasonCache,
        $trustedProviderImagesCache,
        true,
        false,
    );
    assertSameValue(
        'https://provider.invalid/ghosts-s01e06.jpg',
        $trustedProviderEpisode['icon'] ?? null,
        'A trusted provider programme thumbnail must survive exact episode lookup when overwrite is disabled.'
    );
    assertSameValue($trustedProviderEpisode['icon'], $trustedProviderEpisode['images'][0]['url'] ?? null, 'The preserved provider thumbnail should be the first XMLTV image.');
    assertSameValue($trustedProviderEpisode['icon'], $trustedProviderEpisode['images'][array_key_last($trustedProviderEpisode['images'])]['url'] ?? null, 'The preserved provider thumbnail should be the final XMLTV image.');
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg', array_column($trustedProviderEpisode['images'] ?? [], 'url'), true),
        'The exact TMDB episode still should remain available as a typed alternative.'
    );

    $trustedProviderEpisodeScope = [
        'title' => 'Ghosts - Hau den Putz',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Die Geister versuchen, das Haus zu retten.',
        'episode_nums' => [
            ['system' => 'xmltv_ns', 'value' => '0.5.'],
        ],
        'category' => 'Series',
        'icon' => 'https://provider.invalid/ghosts-s01e06.jpg',
        'images' => [[
            'url' => 'https://provider.invalid/ghosts-s01e06.jpg',
            'type' => 'screenshot',
            'orient' => 'L',
            'width' => 1280,
            'height' => 720,
            'size' => 2,
            'source' => 'schedules_direct',
            'scope' => 'episode',
        ]],
    ];
    $trustedProviderEpisodeScopeCache = [];
    $trustedProviderEpisodeScopeSeasonCache = [];
    $trustedProviderEpisodeScopeImagesCache = [];
    enrich(
        $plugin,
        $method,
        $trustedProviderEpisodeScope,
        new TmdbService('ghosts'),
        $trustedProviderEpisodeScopeCache,
        [],
        $trustedProviderEpisodeScopeSeasonCache,
        $trustedProviderEpisodeScopeImagesCache,
        true,
        false,
    );
    assertSameValue(
        'https://provider.invalid/ghosts-s01e06.jpg',
        $trustedProviderEpisodeScope['icon'] ?? null,
        'A trusted provider episode thumbnail must survive exact episode lookup when overwrite is disabled.'
    );
    assertSameValue($trustedProviderEpisodeScope['icon'], $trustedProviderEpisodeScope['images'][0]['url'] ?? null, 'The preserved provider episode thumbnail should be the first XMLTV image.');
    assertSameValue($trustedProviderEpisodeScope['icon'], $trustedProviderEpisodeScope['images'][array_key_last($trustedProviderEpisodeScope['images'])]['url'] ?? null, 'The preserved provider episode thumbnail should be the final XMLTV image.');
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg', array_column($trustedProviderEpisodeScope['images'] ?? [], 'url'), true),
        'The TMDB series backdrop should remain available as a typed alternative.'
    );
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg', array_column($trustedProviderEpisodeScope['images'] ?? [], 'url'), true),
        'The exact TMDB episode still should remain available as a typed alternative.'
    );

    $persistedEpisodePrimary = [
        'title' => 'Ghosts - Der Fahrgeist',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Ein Ausflug bringt die Geister durcheinander.',
        'episode_num' => '0.5.',
        'category' => 'Series',
        'icon' => 'https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg',
        'images' => [
            [
                'url' => 'https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg',
                'type' => 'screenshot',
                'orient' => 'L',
                'width' => 1280,
                'height' => 720,
                'size' => 2,
                'source' => 'tmdb',
                'scope' => 'episode',
            ],
            [
                'url' => 'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
                'type' => 'backdrop',
                'orient' => 'L',
                'width' => 1920,
                'height' => 1080,
                'size' => 1,
                'source' => 'tmdb',
                'scope' => 'series',
                'artwork_quality' => 'tmdb_vote_evidence',
            ],
        ],
    ];
    enrich(
        $plugin,
        $method,
        $persistedEpisodePrimary,
        $episodeTmdb,
        $episodeStillCache,
        [],
        $episodeSeasonCache,
        $episodeImagesCache,
        true,
    );

    assertSameValue(
        'https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg',
        $persistedEpisodePrimary['icon'] ?? null,
        'A rerun should repair a persisted episode-still primary to the trusted series backdrop.'
    );
    assertSameValue(
        $persistedEpisodePrimary['icon'],
        $persistedEpisodePrimary['images'][0]['url'] ?? null,
        'The repaired series backdrop should be the first XMLTV image.'
    );
    assertSameValue(
        $persistedEpisodePrimary['icon'],
        $persistedEpisodePrimary['images'][array_key_last($persistedEpisodePrimary['images'])]['url'] ?? null,
        'The repaired series backdrop should be the final XMLTV image.'
    );
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-s01e06.jpg', array_column($persistedEpisodePrimary['images'] ?? [], 'url'), true),
        'Repairing a persisted primary should preserve the exact episode still as secondary artwork.'
    );

    $episodeWithoutStill = [
        'title' => 'Ghosts - Ohne Szenenbild',
        'tmdb_id' => 104,
        'tmdb_media_type' => 'tv',
        'desc' => 'Für diese Folge ist kein Szenenbild verfügbar.',
        'episode_num' => '0.7',
        'category' => 'Series',
    ];
    enrich(
        $plugin,
        $method,
        $episodeWithoutStill,
        $episodeTmdb,
        $episodeStillCache,
        [],
        $episodeSeasonCache,
        $episodeImagesCache,
        true,
    );
    assertSameValue('https://image.tmdb.org/t/p/original/ghosts-backdrop.jpg', $episodeWithoutStill['icon'] ?? null, 'An episode without a still should deterministically fall back to the series backdrop.');
    assertSameValue(1, $episodeTmdb->seasonRequests, 'A missing episode still should reuse the cached season payload.');

    $sameNameGhostsCache = [];
    $sameNameGhostsTmdb = new TmdbService('same-name-ghosts');
    foreach ([2019 => 107, 2021 => 108] as $year => $tmdbId) {
        $sameNameGhosts = [
            'title' => 'Ghosts - Episode '.$year,
            'tmdb_id' => $tmdbId,
            'tmdb_media_type' => 'tv',
            'desc' => 'Comedy series from '.$year.' about a haunted home.',
            'episode_num' => '1.1',
            'category' => 'Series',
        ];
        $sameNameGhostsResult = enrich($plugin, $method, $sameNameGhosts, $sameNameGhostsTmdb, $sameNameGhostsCache);

        assertSameValue(
            'https://image.tmdb.org/t/p/original/ghosts-'.$tmdbId.'-backdrop.jpg',
            $sameNameGhosts['icon'] ?? null,
            'Ghosts '.$year.' should use artwork from the series with the matching year.'
        );
        assertSameValue(true, $sameNameGhostsResult['lookup'], 'Ghosts '.$year.' should validate its year-specific series identity.');
    }

    $germanSeries = [
        'title' => 'Die Landarztpraxis',
        'tmdb_id' => 105,
        'tmdb_media_type' => 'tv',
        'subtitle' => 'Familienbande',
        'episode_num' => '1.42',
        'desc' => 'Isa kämpft in Wiesenkirchen um ihre Familie.',
        'category' => 'Series',
    ];
    $germanSeriesCache = [];
    enrich($plugin, $method, $germanSeries, new TmdbService('german-series'), $germanSeriesCache);
    assertSameValue('https://image.tmdb.org/t/p/original/landarztpraxis-backdrop.jpg', $germanSeries['icon'] ?? null, 'An exact German series title should use its TV backdrop.');

    $provider = [
        'title' => 'The Long Walk - Der Todesmarsch',
        'date' => '2025',
        'desc' => 'USA 2025. Bei einem Todesmarsch darf niemand stehen bleiben.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/trusted-landscape.jpg',
        'images' => [
            [
                'url' => 'https://provider.invalid/trusted-landscape.jpg',
                'type' => 'fanart',
                'orient' => 'L',
                'width' => 1920,
                'height' => 1080,
            ],
        ],
    ];
    $providerCache = [];
    $providerTmdb = new TmdbService('long-walk');
    $providerResult = enrich($plugin, $method, $provider, $providerTmdb, $providerCache);
    assertSameValue('https://fixture.invalid/movie-backdrop.jpg', $provider['icon'], 'Unscoped landscape branding should be repaired by validated TMDB artwork.');
    assertTrueValue($providerTmdb->tvSearches + $providerTmdb->movieSearches > 0, 'Unscoped complete metadata should not retain the no-op fast path.');
    assertSameValue(true, $providerResult['changed'], 'Unscoped artwork repair should report a change.');

    $scopedProvider = [
        'title' => 'Provider Programme',
        'date' => '2024',
        'desc' => 'Complete provider description.',
        'category' => 'Series',
        'icon' => 'https://provider.invalid/programme-landscape.jpg',
        'images' => [[
            'url' => 'https://provider.invalid/programme-landscape.jpg',
            'type' => 'fanart',
            'orient' => 'L',
            'width' => 1920,
            'height' => 1080,
            'scope' => 'programme',
        ]],
    ];
    $scopedProviderBefore = $scopedProvider;
    $scopedProviderCache = [];
    $scopedProviderTmdb = new TmdbService('none');
    $scopedProviderResult = enrich($plugin, $method, $scopedProvider, $scopedProviderTmdb, $scopedProviderCache);
    assertSameValue($scopedProviderBefore['icon'], $scopedProvider['icon'], 'Explicitly programme-scoped source artwork should remain the primary icon.');
    assertSameValue($scopedProvider['icon'], $scopedProvider['images'][0]['url'], 'Explicitly programme-scoped artwork should remain the first image.');
    assertSameValue($scopedProvider['icon'], $scopedProvider['images'][array_key_last($scopedProvider['images'])]['url'], 'Explicitly programme-scoped artwork should also be the final image.');
    assertSameValue('fanart', $scopedProvider['images'][array_key_last($scopedProvider['images'])]['type'], 'The source primary duplicate should retain its image type.');
    assertTrueValue($scopedProviderTmdb->tvSearches + $scopedProviderTmdb->movieSearches > 0, 'Explicit programme provenance should still evaluate TMDB identity.');
    assertSameValue(true, $scopedProviderResult['changed'], 'Trusted source artwork should report its serialization repair.');

    $ambiguous = [
        'title' => 'Crossroads',
        'date' => '2020',
        'desc' => 'A 2020 drama about several lives meeting at a crossroads.',
        'category' => 'Drama',
        'icon' => 'https://provider.invalid/crossroads-unknown.jpg',
    ];
    $ambiguousBefore = $ambiguous;
    $ambiguousCache = [];
    enrich($plugin, $method, $ambiguous, new TmdbService('ambiguous'), $ambiguousCache);
    $ambiguousComparable = $ambiguous;
    unset($ambiguousComparable['tmdb_decision']);
    assertSameValue($ambiguousBefore, $ambiguousComparable, 'Ambiguous TV and movie candidates should leave the programme unchanged.');
    assertSameValue('ambiguous_identity', $ambiguous['tmdb_decision']['class'] ?? null, 'Ambiguous TV/movie candidates must retain an explicit ambiguity decision.');

    $courtShow = [
        'title' => 'Ulrich Wetzel - Das Strafgericht',
        'date' => '2022',
        'subtitle' => 'Der verschwundene Ring',
        'desc' => 'Vor Gericht stehen sich widersprüchliche Aussagen gegenüber.',
        'category' => 'Series',
    ];
    $courtShowBefore = $courtShow;
    $courtShowCache = [];
    enrich($plugin, $method, $courtShow, new TmdbService('ulrich-wetzel'), $courtShowCache);
    $courtShowComparable = $courtShow;
    unset($courtShowComparable['tmdb_decision']);
    assertSameValue($courtShowBefore, $courtShowComparable, 'An unresolved court-show media-type tie should fail closed after full and base-title validation.');
    assertSameValue('unmatched', $courtShow['tmdb_decision']['result'] ?? null, 'An unresolved media-type tie must retain unmatched evidence.');

    $boston = [
        'title' => 'Boston',
        'tmdb_id' => 204,
        'tmdb_media_type' => 'movie',
        'date' => '2017',
        'desc' => 'Dokumentation aus dem Jahr 2017 über den Anschlag auf den Boston-Marathon.',
        'category' => 'Documentary',
        'icon' => 'https://provider.invalid/boston-portrait.jpg',
        'images' => [[
            'url' => 'https://provider.invalid/boston-portrait.jpg',
            'type' => 'poster',
            'orient' => 'P',
            'width' => 800,
            'height' => 1200,
        ]],
    ];
    $bostonCache = [];
    enrich($plugin, $method, $boston, new TmdbService('boston'), $bostonCache);
    assertSameValue('https://image.tmdb.org/t/p/original/boston-backdrop.jpg', $boston['icon'], 'Boston should replace a portrait-only icon with the validated landscape backdrop.');
    assertSameValue('https://image.tmdb.org/t/p/original/boston-backdrop.jpg', $boston['images'][0]['url'] ?? null, 'Boston landscape artwork should be the first images entry.');
    assertTrueValue(in_array('https://provider.invalid/boston-portrait.jpg', array_column($boston['images'], 'url'), true), 'Boston source portrait artwork should remain available after the backdrop.');

    $posterOnly = [
        'title' => 'Poster Only',
        'tmdb_id' => 209,
        'tmdb_media_type' => 'movie',
        'date' => '2026',
        'desc' => 'A 2026 programme without landscape artwork.',
        'category' => 'Movie',
        'images' => [[
            'url' => 'https://provider.invalid/channel-logo.png',
            'type' => 'logo',
            'orient' => 'L',
            'width' => 2000,
            'height' => 400,
        ]],
    ];
    $posterOnlyCache = [];
    enrich($plugin, $method, $posterOnly, new TmdbService('poster-only'), $posterOnlyCache);
    assertSameValue('https://image.tmdb.org/t/p/w500/poster-only.jpg', $posterOnly['icon'] ?? null, 'A poster should be the programme-thumbnail fallback when no landscape artwork exists.');
    assertSameValue(['poster', 'logo', 'poster'], array_column($posterOnly['images'] ?? [], 'type'), 'The poster fallback should bracket secondary artwork so a logo can never become Emby Primary.');
    assertSameValue($posterOnly['icon'], $posterOnly['images'][0]['url'] ?? null, 'The poster fallback should be the first XMLTV image.');
    assertSameValue($posterOnly['icon'], $posterOnly['images'][array_key_last($posterOnly['images'])]['url'] ?? null, 'The poster fallback should also be the final XMLTV image.');

    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'fixture-key';
    $GLOBALS['tmdbTestSettings']->tmdb_language = 'de-DE';
    $titleCard = [
        'title' => 'Backdrop Quality',
        'tmdb_id' => 210,
        'tmdb_media_type' => 'movie',
        'date' => '2026',
        'desc' => 'A 2026 programme used to verify backdrop quality selection.',
        'category' => 'Movie',
    ];
    $titleCardCache = [];
    $titleCardImagesCache = [];
    Http::$responses[] = new FakeHttpResponse(true, [
        'posters' => [],
        'backdrops' => [[
            'file_path' => '/german-16x9-zero-vote-title-card.jpg',
            'iso_639_1' => 'de',
            'vote_count' => 0,
            'vote_average' => 0.0,
            'aspect_ratio' => 1.778,
        ]],
        'logos' => [],
    ]);
    enrich(
        $plugin,
        $method,
        $titleCard,
        new TmdbService('backdrop-quality'),
        $titleCardCache,
        [],
        $titleCardSeasonCache,
        $titleCardImagesCache,
    );
    assertSameValue(null, $titleCard['icon'] ?? null, 'A zero-vote German 16:9 title card must not become primary artwork.');
    assertSameValue([
        'source' => 'tmdb',
        'asset_type' => 'backdrop',
        'reason' => 'no_backdrop_with_vote_evidence',
    ], $titleCard['artwork_rejection'] ?? null, 'A rejected primary must retain safe TMDB provenance and reason metadata.');
    assertSameValue([], array_values(array_filter(
        $titleCard['images'] ?? [],
        fn (array $image): bool => ($image['type'] ?? null) === 'backdrop'
    )), 'Rejected title-card metadata must not be serialized as a programme backdrop.');

    $roteRosenImages = [
        'posters' => [[
            'file_path' => '/rote-rosen-poster.jpg',
            'iso_639_1' => 'de',
            'vote_count' => 1,
            'vote_average' => 5.0,
            'aspect_ratio' => 0.667,
            'width' => 1000,
            'height' => 1500,
        ]],
        'backdrops' => [[
            'file_path' => '/qZ1odCAlNZhUIeLXZXU06JxRqjo.jpg',
            'iso_639_1' => null,
            'vote_count' => 0,
            'vote_average' => 0.0,
            'aspect_ratio' => 1.778,
            'width' => 1920,
            'height' => 1080,
        ]],
        'logos' => [],
    ];
    $roteRosenNoOverwrite = [
        'title' => 'Rote Rosen',
        'tmdb_id' => 27181,
        'tmdb_media_type' => 'tv',
        'episode_num' => '0.0',
        'desc' => 'Eine Telenovela aus Lueneburg.',
        'category' => 'Series',
    ];
    $roteRosenNoOverwriteCache = [];
    $roteRosenNoOverwriteImagesCache = [];
    Http::$responses[] = new FakeHttpResponse(true, $roteRosenImages);
    enrich(
        $plugin,
        $method,
        $roteRosenNoOverwrite,
        new TmdbService('rote-rosen'),
        $roteRosenNoOverwriteCache,
        [],
        $roteRosenNoOverwriteSeasonCache,
        $roteRosenNoOverwriteImagesCache,
        false,
        false,
    );
    $roteRosenPoster = 'https://image.tmdb.org/t/p/w500/rote-rosen-poster.jpg';
    assertSameValue($roteRosenPoster, $roteRosenNoOverwrite['icon'] ?? null, 'Rote Rosen should use the validated poster fallback without promoting an unrated backdrop.');
    assertSameValue($roteRosenPoster, $roteRosenNoOverwrite['images'][0]['url'] ?? null, 'The validated poster fallback should be the first image.');
    assertSameValue($roteRosenPoster, $roteRosenNoOverwrite['images'][array_key_last($roteRosenNoOverwrite['images'])]['url'] ?? null, 'The validated poster fallback should also be the final image.');

    $roteRosenOverwrite = [
        'title' => 'Rote Rosen',
        'tmdb_id' => 27181,
        'tmdb_media_type' => 'tv',
        'episode_num' => '0.0',
        'desc' => 'Eine Telenovela aus Lueneburg.',
        'category' => 'Series',
    ];
    $roteRosenOverwriteCache = [];
    $roteRosenOverwriteImagesCache = [];
    Http::$responses[] = new FakeHttpResponse(true, $roteRosenImages);
    enrich(
        $plugin,
        $method,
        $roteRosenOverwrite,
        new TmdbService('rote-rosen'),
        $roteRosenOverwriteCache,
        [],
        $roteRosenOverwriteSeasonCache,
        $roteRosenOverwriteImagesCache,
        false,
        true,
    );
    $roteRosenBackdrop = 'https://image.tmdb.org/t/p/original/qZ1odCAlNZhUIeLXZXU06JxRqjo.jpg';
    assertSameValue($roteRosenBackdrop, $roteRosenOverwrite['icon'] ?? null, 'Overwrite mode should select the exact validated Rote Rosen details backdrop.');
    assertSameValue($roteRosenBackdrop, $roteRosenOverwrite['images'][0]['url'] ?? null, 'The unrated Rote Rosen primary must be the first image.');
    assertSameValue($roteRosenBackdrop, $roteRosenOverwrite['images'][array_key_last($roteRosenOverwrite['images'])]['url'] ?? null, 'The unrated Rote Rosen primary must also be the last image.');
    assertSameValue('tmdb', $roteRosenOverwrite['images'][0]['source'] ?? null, 'The unrated fallback must retain TMDB provenance.');
    assertSameValue('programme', $roteRosenOverwrite['images'][0]['scope'] ?? null, 'The unrated fallback must retain programme scope.');
    assertSameValue('tmdb_details_unrated_fallback', $roteRosenOverwrite['images'][0]['artwork_quality'] ?? null, 'The unrated fallback must have distinct quality provenance.');
    assertTrueValue(in_array('poster', array_column($roteRosenOverwrite['images'], 'type'), true), 'The Rote Rosen poster must remain secondary artwork.');

    $roteRosenCategory = [
        'title' => 'Rote Rosen',
        'tmdb_id' => 27181,
        'tmdb_media_type' => 'tv',
        'episode_num' => '0.0',
        'desc' => 'Eine Telenovela aus Lueneburg.',
        'category' => ['Soap', 'Drama'],
    ];
    $roteRosenCategoryCache = [];
    $roteRosenCategoryImagesCache = [];
    $categoryWarnings = [];
    Http::$responses[] = new FakeHttpResponse(true, $roteRosenImages);
    set_error_handler(function (int $severity, string $message) use (&$categoryWarnings): bool {
        if (str_contains($message, 'Array to string conversion')) {
            $categoryWarnings[] = $message;

            return true;
        }

        return false;
    });
    try {
        enrich(
            $plugin,
            $method,
            $roteRosenCategory,
            new TmdbService('rote-rosen'),
            $roteRosenCategoryCache,
            [],
            $roteRosenCategorySeasonCache,
            $roteRosenCategoryImagesCache,
            false,
            true,
            true,
        );
    } finally {
        restore_error_handler();
    }
    assertSameValue([], $categoryWarnings, 'Provider category arrays must not trigger array-to-string warnings.');
    assertSameValue('Series', $roteRosenCategory['category'] ?? null, 'Validated TV data with Emby mapping must compact provider category arrays to Series.');

    $sceneControl = [
        'title' => 'Backdrop Quality',
        'tmdb_id' => 210,
        'tmdb_media_type' => 'movie',
        'date' => '2026',
        'desc' => 'A 2026 programme used to verify backdrop quality selection.',
        'category' => 'Movie',
    ];
    $sceneControlCache = [];
    $sceneControlImagesCache = [];
    Http::$responses[] = new FakeHttpResponse(true, [
        'posters' => [],
        'backdrops' => [
            [
                'file_path' => '/german-16x9-zero-vote-title-card.jpg',
                'iso_639_1' => 'de',
                'vote_count' => 0,
                'vote_average' => 0.0,
                'aspect_ratio' => 1.778,
            ],
            [
                'file_path' => '/scene-key-art-control.jpg',
                'iso_639_1' => null,
                'vote_count' => 4,
                'vote_average' => 7.0,
                'aspect_ratio' => 1.778,
            ],
        ],
        'logos' => [],
    ]);
    enrich(
        $plugin,
        $method,
        $sceneControl,
        new TmdbService('backdrop-quality'),
        $sceneControlCache,
        [],
        $sceneControlSeasonCache,
        $sceneControlImagesCache,
    );
    assertSameValue('https://image.tmdb.org/t/p/w1280/scene-key-art-control.jpg', $sceneControl['icon'] ?? null, 'Suitable scene metadata must beat the zero-vote title-card backdrop.');
    assertSameValue('tmdb_vote_evidence', $sceneControl['images'][0]['artwork_quality'] ?? null, 'Selected scene artwork must retain its TMDB metadata provenance.');
    assertSameValue(false, isset($sceneControl['artwork_rejection']), 'A suitable scene candidate must clear the rejection state.');
    $artworkQualityEvidence = [
        'rejected_zero_vote_title_cards' => 1,
        'selected_scene_controls' => 1,
    ];

    Http::$responses[] = new FakeHttpResponse(true, [
        'posters' => [
            ['file_path' => '/default-poster.jpg', 'iso_639_1' => 'en', 'vote_average' => 9.0, 'aspect_ratio' => 0.667],
            ['file_path' => '/german-poster.jpg', 'iso_639_1' => 'de', 'vote_average' => 7.0, 'aspect_ratio' => 0.667],
        ],
        'backdrops' => [],
        'logos' => [],
    ]);
    $localizedPoster = [
        'title' => 'Localized Poster',
        'tmdb_id' => 208,
        'tmdb_media_type' => 'movie',
        'date' => '2026',
        'desc' => 'A 2026 localized poster fixture.',
        'category' => 'Movie',
    ];
    $localizedPosterCache = [];
    $localizedSeasonCache = [];
    $localizedImagesCache = [];
    enrich(
        $plugin,
        $method,
        $localizedPoster,
        new TmdbService('localized-poster'),
        $localizedPosterCache,
        ['tmdb_language' => 'de-DE'],
        $localizedSeasonCache,
        $localizedImagesCache,
    );
    $localizedPosters = array_values(array_filter(
        $localizedPoster['images'] ?? [],
        fn (array $image): bool => ($image['type'] ?? null) === 'poster'
    ));
    assertSameValue('https://image.tmdb.org/t/p/w500/german-poster.jpg', $localizedPosters[0]['url'] ?? null, 'Configured base language should outrank an unverified default poster.');
    assertSameValue('de', $localizedPosters[0]['language'] ?? null, 'The poster winner should retain its TMDB language metadata.');
    $defaultPoster = array_values(array_filter(
        $localizedPosters,
        fn (array $image): bool => ($image['url'] ?? null) === 'https://image.tmdb.org/t/p/w500/default-poster.jpg'
    ));
    assertSameValue('en', $defaultPoster[0]['language'] ?? null, 'A duplicate detail URL should retain the higher-confidence images endpoint metadata.');

    $selectImages = $reflection->getMethod('selectImageSet');
    $selectImages->setAccessible(true);
    $canonicalBackdrop = 'https://image.tmdb.org/t/p/original/canonical-details-backdrop.jpg';
    $canonicalCompetition = $selectImages->invoke($plugin, [
        'posters' => [],
        'backdrops' => [
            [
                'file_path' => '/canonical-details-backdrop.jpg',
                'iso_639_1' => 'en',
                'vote_count' => 1,
                'vote_average' => 2.0,
                'width' => 1920,
                'height' => 1080,
            ],
            [
                'file_path' => '/german-higher-vote-backdrop.jpg',
                'iso_639_1' => 'de',
                'vote_count' => 50,
                'vote_average' => 9.0,
                'width' => 1920,
                'height' => 1080,
            ],
        ],
        'logos' => [],
    ], 'de-DE', $canonicalBackdrop);
    assertSameValue(
        'https://image.tmdb.org/t/p/w1280/canonical-details-backdrop.jpg',
        $canonicalCompetition[0]['url'] ?? null,
        'A genuine canonical details backdrop must outrank a language- or vote-ranked alternative.'
    );

    $sourcePrimary = 'https://provider.invalid/programme-primary.jpg';
    $sourceLandscape = [
        'title' => 'Backdrop Quality',
        'tmdb_id' => 210,
        'tmdb_media_type' => 'movie',
        'date' => '2026',
        'desc' => 'A 2026 programme used to verify backdrop quality selection.',
        'category' => 'Movie',
        'icon' => $sourcePrimary,
        'images' => [[
            'url' => $sourcePrimary,
            'type' => 'fanart',
            'orient' => 'L',
            'width' => 1920,
            'height' => 1080,
            'scope' => 'programme',
        ]],
    ];
    $sourceLandscapeInput = $sourceLandscape;
    $sourceLandscapeCache = [];
    $sourceLandscapeImagesCache = [];
    Http::$responses[] = new FakeHttpResponse(true, [
        'posters' => [],
        'backdrops' => [
            [
                'file_path' => '/details-backdrop.jpg',
                'iso_639_1' => 'en',
                'vote_count' => 1,
                'vote_average' => 2.0,
                'width' => 1920,
                'height' => 1080,
            ],
            [
                'file_path' => '/german-higher-vote-backdrop.jpg',
                'iso_639_1' => 'de',
                'vote_count' => 50,
                'vote_average' => 9.0,
                'width' => 1920,
                'height' => 1080,
            ],
        ],
        'logos' => [],
    ]);
    $sourceLandscapeResult = enrich(
        $plugin,
        $method,
        $sourceLandscape,
        new TmdbService('backdrop-quality'),
        $sourceLandscapeCache,
        [],
        $sourceLandscapeSeasonCache,
        $sourceLandscapeImagesCache,
    );
    assertSameValue($sourcePrimary, $sourceLandscape['icon'], 'A trusted non-TMDB source landscape must remain primary without overwrite.');
    assertSameValue(true, $sourceLandscapeResult['lookup'], 'A trusted non-TMDB source landscape must not bypass TMDB identity evaluation.');
    assertSameValue('tmdb_details_backdrop_preferred', $sourceLandscape['artwork_decision']['reason'] ?? null, 'Artwork provenance must record the canonical-details decision.');
    assertSameValue(210, $sourceLandscape['artwork_decision']['tmdb_id'] ?? null, 'Artwork provenance must record the selected TMDB identity.');
    assertSameValue('movie', $sourceLandscape['artwork_decision']['media_type'] ?? null, 'Artwork provenance must record the selected media type.');
    assertSameValue(true, $sourceLandscape['artwork_decision']['details_path_equality'] ?? null, 'Artwork provenance must record details-path equality.');
    assertTrueValue(isset($sourceLandscape['artwork_decision']['winner_path_hash']), 'Artwork provenance must fingerprint the winner without persisting its path.');
    assertTrueValue(
        preg_match('/^[a-f0-9]{64}$/', $sourceLandscape['artwork_decision']['input_fingerprint'] ?? '') === 1,
        'Artwork provenance must contain a non-empty SHA-256 fingerprint of normalized input.'
    );
    assertSameValue(
        hash('sha256', json_encode([
            'title' => 'backdrop quality',
            'description' => 'a 2026 programme used to verify backdrop quality selection',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        $sourceLandscape['artwork_decision']['input_fingerprint'],
        'Artwork provenance input fingerprint must be stable for normalized title and description.'
    );
    $serializedSourceDecision = json_encode($sourceLandscape['artwork_decision'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    assertTrueValue(
        ! str_contains($serializedSourceDecision, 'provider.invalid')
            && ! str_contains($serializedSourceDecision, $sourceLandscape['desc']),
        'Artwork provenance must not serialize the provider host or raw programme description.'
    );
    $sourceLandscapeReplay = $sourceLandscapeInput;
    $sourceLandscapeReplayResult = enrich(
        $plugin,
        $method,
        $sourceLandscapeReplay,
        new TmdbService('backdrop-quality'),
        $sourceLandscapeCache,
        [],
        $sourceLandscapeSeasonCache,
        $sourceLandscapeImagesCache,
    );
    assertSameValue(true, $sourceLandscapeReplayResult['cache_hit'], 'The repeated programme should reuse its validated identity cache entry.');
    assertSameValue(false, $sourceLandscape['artwork_decision']['cache_hit'] ?? null, 'The first artwork decision must persist the cache miss provenance.');
    assertSameValue(true, $sourceLandscapeReplay['artwork_decision']['cache_hit'] ?? null, 'The repeated artwork decision must persist the cache hit provenance.');
    $sourceLandscapeComparable = $sourceLandscape;
    $sourceLandscapeReplayComparable = $sourceLandscapeReplay;
    unset($sourceLandscapeComparable['artwork_decision']['cache_hit'], $sourceLandscapeReplayComparable['artwork_decision']['cache_hit']);
    assertSameValue($sourceLandscapeComparable, $sourceLandscapeReplayComparable, 'Artwork output must remain deterministic apart from the required cache provenance.');
    $branchBEvidence = [
        'canonical_details_preferred' => 1,
        'source_primary_lookup_evaluated' => 1,
        'reason_codes' => ['tmdb_details_backdrop_preferred'],
    ];

    $languagePriority = $selectImages->invoke($plugin, [
        'posters' => [
            ['file_path' => '/english.jpg', 'iso_639_1' => 'en', 'vote_average' => 10.0],
            ['file_path' => '/neutral.jpg', 'iso_639_1' => null, 'vote_average' => 1.0],
            ['file_path' => '/german.jpg', 'iso_639_1' => 'de', 'vote_average' => 1.0],
        ],
        'backdrops' => [],
        'logos' => [],
    ], 'de-DE');
    assertSameValue(
        ['https://image.tmdb.org/t/p/w500/german.jpg', 'https://image.tmdb.org/t/p/w500/neutral.jpg'],
        array_column($languagePriority, 'url'),
        'Poster selection should prefer configured language, then neutral, before the English fallback.'
    );

    $fetchImages = $reflection->getMethod('fetchTmdbImages');
    $fetchImages->setAccessible(true);
    $imageCache = [];
    Http::$calls = [];
    Http::$responses = [];
    Http::$responses[] = new FakeHttpResponse(false);
    $failedFetch = $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    assertSameValue(null, $failedFetch, 'Transient image failures should return null.');
    assertSameValue([], $imageCache, 'Transient null image responses should not be cached.');

    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    assertSameValue(2, count(Http::$calls), 'A successful images payload should be reused for the same media identity and language.');

    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [99, 'tv', &$imageCache]);
    $GLOBALS['tmdbTestSettings']->tmdb_language = 'en-US';
    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [100, 'movie', &$imageCache]);
    assertSameValue(
        ['movie:99:de-de', 'tv:99:de-de', 'movie:99:en-us', 'movie:100:en-us'],
        array_keys($imageCache),
        'Image cache identity should isolate media type, TMDB ID, and selected language.'
    );
    assertSameValue(5, count(Http::$calls), 'Each distinct image cache identity should fetch exactly once after the transient failure.');

    $finalizeImageSerialization = $reflection->getMethod('finalizeImageSerialization');
    $finalizeImageSerialization->setAccessible(true);
    $mixedArtworkProgramme = [
        'icon' => 'https://provider.invalid/mixed-poster.jpg',
        'images' => [
            [
                'url' => 'https://provider.invalid/mixed-poster.jpg',
                'type' => 'poster',
                'orient' => 'P',
                'width' => 800,
                'height' => 1200,
                'scope' => 'programme',
            ],
            [
                'url' => 'https://provider.invalid/mixed-fanart.jpg',
                'type' => 'fanart',
                'orient' => 'L',
                'width' => 1920,
                'height' => 1080,
                'scope' => 'programme',
            ],
        ],
    ];
    $finalizeImageSerialization->invokeArgs($plugin, [&$mixedArtworkProgramme, false, false]);
    assertSameValue('https://provider.invalid/mixed-fanart.jpg', $mixedArtworkProgramme['icon'], 'Trusted fanart must outrank a current provider poster as the programme primary.');
    assertSameValue($mixedArtworkProgramme['icon'], $mixedArtworkProgramme['images'][0]['url'], 'Trusted fanart must be the first XMLTV image.');
    assertSameValue($mixedArtworkProgramme['icon'], $mixedArtworkProgramme['images'][array_key_last($mixedArtworkProgramme['images'])]['url'], 'Trusted fanart must be the final XMLTV image.');
    assertSameValue('poster', $mixedArtworkProgramme['images'][1]['type'] ?? null, 'The provider poster must remain a typed secondary alternative.');
    $benchmarkParity = ['denominator' => 0, 'numerator' => 0];
    foreach (range(1, 101) as $index) {
        $primaryType = ['backdrop', 'fanart', 'screenshot'][($index - 1) % 3];
        $primaryUrl = "https://fixture.invalid/issue47-primary-{$index}.jpg";
        $primary = [
            'url' => $primaryUrl,
            'type' => $primaryType,
            'orient' => 'L',
            'width' => 1920,
            'height' => 1080,
            'source' => 'tmdb',
            'scope' => $primaryType === 'screenshot' ? 'episode' : 'programme',
        ];
        if ($primaryType === 'backdrop') {
            $primary['artwork_quality'] = 'tmdb_vote_evidence';
        }
        $benchmarkProgramme = [
            'icon' => $primaryUrl,
            'images' => [
                $primary,
                ['url' => "https://fixture.invalid/issue47-poster-{$index}.jpg", 'type' => 'poster', 'orient' => 'P', 'width' => 500, 'height' => 750],
                ['url' => "https://fixture.invalid/issue47-logo-{$index}.png", 'type' => 'logo', 'orient' => 'L', 'width' => 500, 'height' => 200],
            ],
        ];
        $finalizeImageSerialization->invokeArgs($plugin, [&$benchmarkProgramme, true, false]);
        assertSameValue($primaryUrl, $benchmarkProgramme['images'][0]['url'], 'Every Issue 47 synthetic primary should remain first.');
        assertSameValue($primaryUrl, $benchmarkProgramme['images'][array_key_last($benchmarkProgramme['images'])]['url'], 'Every Issue 47 synthetic primary should be duplicated last.');
        assertSameValue($primaryType, $benchmarkProgramme['images'][array_key_last($benchmarkProgramme['images'])]['type'], 'Every terminal Issue 47 primary duplicate should retain its type.');
        $benchmarkParity['denominator']++;
        if ($benchmarkProgramme['images'][0]['url'] === $benchmarkProgramme['images'][array_key_last($benchmarkProgramme['images'])]['url']) {
            $benchmarkParity['numerator']++;
        }

        $serializedBenchmarkProgramme = json_encode($benchmarkProgramme, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $finalizeImageSerialization->invokeArgs($plugin, [&$benchmarkProgramme, true, false]);
        assertSameValue($serializedBenchmarkProgramme, json_encode($benchmarkProgramme, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'Issue 47 serialization should be deterministic on repeat runs.');
    }

    $skySport = [
        'title' => 'Sky Sport News Live',
        'desc' => 'Provider-only bulletin from https://provider.example.invalid/export.',
        'category' => 'Sports',
        'icon' => 'https://provider.example.invalid/sky-sport.jpg',
        'images' => [[
            'url' => 'https://provider.example.invalid/sky-sport.jpg',
            'type' => 'fanart',
            'orient' => 'L',
            'width' => 1920,
            'height' => 1080,
            'scope' => 'programme',
        ]],
    ];
    $skySportTmdb = new CandidateTmdbService();
    $skySportCache = [];
    enrich($plugin, $method, $skySport, $skySportTmdb, $skySportCache);
    assertSameValue([0, 0], [$skySportTmdb->tvCandidateSearches, $skySportTmdb->movieCandidateSearches], 'A generic Sky Sport live row must not search TMDB when category mapping is disabled.');
    assertSameValue('unknown', $skySport['tmdb_decision']['class'] ?? null, 'A brand-shaped title-only row must remain UNKNOWN while trusted provider artwork is preserved.');
    assertSameValue($skySport['icon'], $skySport['images'][0]['url'] ?? null, 'Preserved provider artwork must remain the first XMLTV primary.');
    assertSameValue($skySport['icon'], $skySport['images'][array_key_last($skySport['images'])]['url'] ?? null, 'Preserved provider artwork must remain the final XMLTV primary.');

    $dazn = ['title' => 'DAZN Live', 'category' => 'Sports'];
    $daznTmdb = new CandidateTmdbService();
    $daznCache = [];
    enrich($plugin, $method, $dazn, $daznTmdb, $daznCache);
    assertSameValue([0, 0], [$daznTmdb->tvCandidateSearches, $daznTmdb->movieCandidateSearches], 'A DAZN-shaped row without identity must not search TMDB.');
    assertSameValue('unknown', $dazn['tmdb_decision']['class'] ?? null, 'A brand-shaped title-only row without artwork must remain UNKNOWN.');

    $catalogueControlDetails = normalizedTvDetailsFixture(
        801,
        'Archive Sprint',
        'A clearly identified non-live sports documentary.',
        backdropUrl: 'https://image.tmdb.org/t/p/original/archive-sprint.jpg',
    );
    $catalogueControlTmdb = new CandidateTmdbService(
        tvCandidates: [[
            'tmdb_id' => 801,
            'name' => 'Archive Sprint',
            'original_name' => 'Archive Sprint',
            'first_air_date' => '2024-01-01',
            'overview' => 'A clearly identified non-live sports documentary.',
        ]],
        tvDetails: [801 => $catalogueControlDetails],
    );
    $catalogueControl = ['title' => 'Archive Sprint', 'category' => 'Sports', 'date' => '2024'];
    $catalogueControlCache = [];
    enrich($plugin, $method, $catalogueControl, $catalogueControlTmdb, $catalogueControlCache);
    assertTrueValue($catalogueControlTmdb->tvCandidateSearches > 0, 'A clearly identified non-live catalogue programme must remain eligible for TMDB matching.');
    assertSameValue('catalogue_candidate', $catalogueControl['tmdb_decision']['class'] ?? null, 'Eligible catalogue rows must retain their applicability class.');
    assertSameValue('selected', $catalogueControl['tmdb_decision']['result'] ?? null, 'A selected candidate must retain selected decision evidence.');
    assertTrueValue(is_numeric($catalogueControl['tmdb_decision']['score'] ?? null), 'Selected decision evidence must retain its bounded score.');

    $pickupReadback = ['title' => 'Pick-up Truckers', 'episode_num' => '0.0'];
    $pickupTmdb = new CandidateTmdbService();
    $pickupCache = [];
    enrich($plugin, $method, $pickupReadback, $pickupTmdb, $pickupCache);
    assertSameValue('unmatched', $pickupReadback['tmdb_decision']['result'] ?? null, 'A zero readback hit must be represented as unmatched evidence rather than success.');
    assertTrueValue(! isset($pickupReadback['tmdb_decision']['selected_candidate_fingerprint']), 'Unmatched evidence must not claim a selected candidate.');

    $serializedDecision = json_encode($skySport['tmdb_decision'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    assertTrueValue(
        ! str_contains($serializedDecision, 'provider.example.invalid')
            && ! str_contains($serializedDecision, $skySport['desc'])
            && ! str_contains($serializedDecision, 'https://'),
        'TMDB decisions must not persist provider hosts, raw descriptions, or URLs.'
    );

    echo "TMDB artwork repair tests passed.\n";
    echo json_encode([
        'first_last_boundary_parity' => $benchmarkParity,
        'artwork_quality_evidence' => $artworkQualityEvidence,
        'branch_b_artwork_evidence' => $branchBEvidence,
        'fixture_egress' => count(Http::$calls),
    ], JSON_THROW_ON_ERROR)."\n";
}
