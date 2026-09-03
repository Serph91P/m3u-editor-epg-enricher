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
                default => null,
            };
        }

        public function getTvSeriesDetails(int $tmdbId): mixed
        {
            $this->tvDetailsRequests++;

            return match ($this->scenario) {
                'long-walk' => [
                    'overview' => 'An unrelated reality competition.',
                    'poster_url' => 'https://fixture.invalid/tv-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/tv-backdrop.jpg',
                ],
                'illuminati' => ['overview' => 'A modern secret society drama.'],
                'bares' => [
                    'overview' => 'Horst Lichter präsentiert seltene Fundstücke, die anschließend von Händlern ersteigert werden können.',
                    'poster_url' => 'https://fixture.invalid/bares-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/bares-backdrop.jpg',
                    'genres' => 'Reality',
                ],
                'ambiguous' => [
                    'overview' => 'Several lives meet at a crossroads.',
                    'poster_url' => 'https://fixture.invalid/crossroads-tv-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/crossroads-tv-backdrop.jpg',
                ],
                'ghosts' => [
                    'overview' => 'A young couple inherit a country estate occupied by ghosts.',
                    'poster_url' => 'https://fixture.invalid/ghosts-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/ghosts-backdrop.jpg',
                    'genres' => 'Comedy',
                ],
                'same-name-ghosts' => [
                    'overview' => 'A comedy about ghosts.',
                    'poster_url' => 'https://fixture.invalid/ghosts-'.$tmdbId.'-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/ghosts-'.$tmdbId.'-backdrop.jpg',
                    'genres' => 'Comedy',
                ],
                'german-series' => [
                    'overview' => 'Eine Ärztin beginnt ein neues Leben in Wiesenkirchen.',
                    'poster_url' => 'https://fixture.invalid/landarztpraxis-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/landarztpraxis-backdrop.jpg',
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
                default => null,
            };
        }

        public function getMovieDetails(int $tmdbId): mixed
        {
            $this->movieDetailsRequests++;

            return match ($this->scenario) {
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
                    'poster_url' => 'https://fixture.invalid/boston-poster.jpg',
                    'backdrop_url' => 'https://fixture.invalid/boston-backdrop.jpg',
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
                    'backdrop_url' => 'https://fixture.invalid/localized-backdrop.jpg',
                ],
                'poster-only' => [
                    'overview' => 'A programme without landscape artwork.',
                    'poster_url' => 'https://fixture.invalid/poster-only.jpg',
                ],
                'backdrop-quality' => [
                    'overview' => 'A programme used to verify backdrop quality selection.',
                    'backdrop_url' => 'https://image.tmdb.org/t/p/original/details-backdrop.jpg',
                ],
                default => null,
            };
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

            return array_key_exists($name, $this->tvCandidatesByQuery)
                ? $this->tvCandidatesByQuery[$name]
                : $this->tvCandidates;
        }

        public function searchMovieCandidates(string $title, ?int $year = null, int $limit = 5): array
        {
            $this->movieCandidateSearches++;
            $this->movieLimits[] = $limit;
            $this->movieQueries[] = $title;
            if ($this->throwOnMovieCandidates) {
                throw new \RuntimeException('synthetic movie candidate failure');
            }

            return array_key_exists($title, $this->movieCandidatesByQuery)
                ? $this->movieCandidatesByQuery[$title]
                : $this->movieCandidates;
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

            return [];
        }

        public function getMovieAlternativeTitles(int $tmdbId): array
        {
            $this->movieAlternativeRequests++;

            return [];
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

        return $method->invokeArgs($plugin, [
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
    }

    function validatedSearch(
        Plugin $plugin,
        ReflectionMethod $method,
        TmdbService $tmdb,
        string $title,
        ?string $mediaType = null,
        ?int $year = null,
        string $description = '',
    ): ?array {
        return $method->invoke($plugin, $tmdb, $title, $mediaType, $year, $description);
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
    $genericClassicProgramme = ['title' => 'Beacon Vale Classics (12)'];
    $genericClassicCache = [];
    enrich($plugin, $method, $genericClassicProgramme, $genericClassicTmdb, $genericClassicCache);
    assertSameValue(
        'https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg',
        $genericClassicProgramme['icon'] ?? null,
        'Any episodic Classics title should retry the generic base series and select a validated TMDB candidate.'
    );
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->tvQueries, 'Generic edition matching must search the full TV title before the derived base title.');
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->movieQueries, 'Generic edition matching must search the full movie title before the derived base title.');
    assertSameValue([1, 0], [$genericClassicTmdb->tvDetailsRequests, $genericClassicTmdb->movieDetailsRequests], 'Only the globally validated generic edition winner should load details.');

    $genericSeriesTmdb = new CandidateTmdbService(
        tvCandidatesByQuery: ['Beacon Vale Classics' => []],
        movieCandidatesByQuery: ['Beacon Vale Classics' => []],
    );
    $genericSeriesProgramme = ['title' => 'Beacon Vale Classics'];
    $genericSeriesCache = [];
    enrich($plugin, $method, $genericSeriesProgramme, $genericSeriesTmdb, $genericSeriesCache);
    assertSameValue(null, $genericSeriesProgramme['icon'] ?? null, 'A plain Classics title without episode evidence must abstain when the full title has no validated candidate.');
    assertSameValue(['Beacon Vale Classics'], $genericSeriesTmdb->tvQueries, 'A plain Classics title without episode evidence must not retry a shortened TV title.');
    assertSameValue(['Beacon Vale Classics'], $genericSeriesTmdb->movieQueries, 'A plain Classics title without episode evidence must not retry a shortened movie title.');
    assertSameValue([0, 0], [$genericSeriesTmdb->tvDetailsRequests, $genericSeriesTmdb->movieDetailsRequests], 'An abstaining plain Classics title must not load details.');

    $genericSeriesReplay = ['title' => 'Beacon Vale Classics (12)'];
    enrich($plugin, $method, $genericSeriesReplay, $genericClassicTmdb, $genericClassicCache);
    assertSameValue($genericClassicProgramme, $genericSeriesReplay, 'A repeated globally matched episodic title should replay the same trusted output.');
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->tvQueries, 'A validated generic cache hit must not repeat TV candidate searches.');
    assertSameValue(['Beacon Vale Classics (12)', 'Beacon Vale'], $genericClassicTmdb->movieQueries, 'A validated generic cache hit must not repeat movie candidate searches.');
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
        $episodeProgramme = ['title' => $episodeTitle];
        $episodeCache = [];
        enrich($plugin, $method, $episodeProgramme, $episodeTmdb, $episodeCache);
        assertSameValue('https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg', $episodeProgramme['icon'] ?? null, $episodeTitle.' should resolve through the same generic base-title pipeline.');
        assertSameValue([$episodeTitle, 'Beacon Vale'], $episodeTmdb->tvQueries, $episodeTitle.' should search the full TV title before the derived base title.');
        assertSameValue([$episodeTitle, 'Beacon Vale'], $episodeTmdb->movieQueries, $episodeTitle.' should search the full movie title before the derived base title.');
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
    $normalizedEditionProgramme = ['title' => '  BEACON-VALE,   CLASSICS Folge 9  '];
    $normalizedEditionCache = [];
    enrich($plugin, $method, $normalizedEditionProgramme, $normalizedEditionTmdb, $normalizedEditionCache);
    assertSameValue('https://image.tmdb.org/t/p/original/beacon-vale-backdrop.jpg', $normalizedEditionProgramme['icon'] ?? null, 'Case, whitespace, and punctuation variants should use generic normalized candidate matching.');
    assertSameValue(['  BEACON-VALE,   CLASSICS Folge 9  ', 'BEACON-VALE,'], $normalizedEditionTmdb->tvQueries, 'Normalized title variants must preserve the full-then-base TV query order.');
    assertSameValue(['  BEACON-VALE,   CLASSICS Folge 9  ', 'BEACON-VALE,'], $normalizedEditionTmdb->movieQueries, 'Normalized title variants must preserve the full-then-base movie query order.');

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
    $globalMovieProgramme = ['title' => 'Kestrel Ridge - A Winter Chronicle'];
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
    $mismatchedCompoundProgramme = ['title' => 'Kestrel Ridge - A Winter Chronicle'];
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
    $literalClassicsProgramme = ['title' => 'Fictional Archive Classics'];
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
    $cacheGuardProgramme = ['title' => 'Cache Guard Series', 'episode_num' => '0.0.'];
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
    assertSameValue([1, 1], [$cacheGuardRepairTmdb->tvCandidateSearches, $cacheGuardRepairTmdb->movieCandidateSearches], 'Malformed generic cache data must perform at most one bounded lookup per media type.');
    assertSameValue(711, $cacheGuardCache[$cacheGuardKey]['tmdb_id'] ?? null, 'A repaired generic cache entry must persist the validated native identity.');

    $cacheGuardCache[$cacheGuardKey] = $maliciousCacheEntry;
    $cacheGuardFailure = $cacheGuardProgramme;
    $cacheGuardFailureBefore = $cacheGuardFailure;
    enrich($plugin, $method, $cacheGuardFailure, $cacheGuardService(true), $cacheGuardCache);
    assertSameValue($cacheGuardFailureBefore, $cacheGuardFailure, 'Malformed generic cache data must fail closed when bounded reload fails.');
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
        ['tmdb_id' => 642, 'name' => 'Margin Target', 'original_name' => 'Margin Target', 'first_air_date' => '2023-01-01', 'overview' => 'Shared alpha evidence.'],
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
        assertSameValue($invalidIdBefore, $invalidIdProgramme, ucfirst($label).' raw candidate ID must not modify programme data.');
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
    $missingDetailsProgramme = ['title' => 'Missing Details Identity'];
    $missingDetailsBefore = $missingDetailsProgramme;
    $missingDetailsCache = [];
    enrich($plugin, $method, $missingDetailsProgramme, $missingDetailsEnrichmentTmdb, $missingDetailsCache);
    assertSameValue($missingDetailsBefore, $missingDetailsProgramme, 'Missing winner details should not modify programme data.');
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
    assertSameValue([200, 'movie'], [$legacyWinner['tmdb_id'] ?? null, $legacyWinner['_media_type'] ?? null], 'An older host without candidate methods should retain one-result fallback selection.');

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
    $abstentionProgramme = ['title' => 'Balanced Identity'];
    $abstentionBefore = $abstentionProgramme;
    $abstentionCache = [];
    enrich($plugin, $method, $abstentionProgramme, $abstentionTmdb, $abstentionCache);
    assertSameValue($abstentionBefore, $abstentionProgramme, 'Identity abstention should not modify programme data.');
    assertSameValue([], $abstentionCache, 'Identity abstention should not write a TMDB cache entry.');
    assertSameValue(0, $abstentionTmdb->tvDetailsRequests + $abstentionTmdb->movieDetailsRequests, 'A global tie must not load any details.');

    $longWalk = [
        'title' => 'The Long Walk - Der Todesmarsch',
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
        'desc' => 'Thriller 2009 von Ron Howard mit Tom Hanks, Ewan McGregor und Ayelet Zurer.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/illuminati-unknown.jpg',
    ];
    enrich($plugin, $method, $illuminati, $illuminatiTmdb, $illuminatiCache);
    assertSameValue('https://fixture.invalid/illuminati-backdrop.jpg', $illuminati['icon'], 'Strong German alternative-title evidence should match Angels & Demons.');

    $weakIlluminati = [
        'title' => 'Illuminati',
        'desc' => 'Mystery thriller from 2009.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/weak-unknown.jpg',
    ];
    $weakBefore = $weakIlluminati;
    enrich($plugin, $method, $weakIlluminati, $illuminatiTmdb, $illuminatiCache);
    assertSameValue($weakBefore, $weakIlluminati, 'Alternative-title matches without description corroboration should fail closed.');
    assertSameValue(1, count($illuminatiCache), 'Identity abstention should not persist a cache entry.');

    $sourceCache = [];
    $sourceA = ['title' => 'Global Identity'];
    enrich($plugin, $method, $sourceA, new TmdbService('identity-movie-a'), $sourceCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $sourceB = ['title' => 'Global Identity'];
    $sourceBResult = enrich($plugin, $method, $sourceB, new TmdbService('identity-movie-b'), $sourceCache, [
        'epg_source_id' => 'source-b',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://fixture.invalid/identity-movie-b.jpg', $sourceB['icon'] ?? null, 'Same-title records without year or description should be isolated by EPG source.');
    assertSameValue(true, $sourceBResult['lookup'], 'A different EPG source should perform its own TMDB lookup.');

    $languageCache = [];
    $english = ['title' => 'Language Identity - Episode One', 'episode_num' => '0.0'];
    enrich($plugin, $method, $english, new TmdbService('identity-tv-a'), $languageCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $german = ['title' => 'Language Identity - Episode Two', 'episode_num' => '0.1'];
    $germanResult = enrich($plugin, $method, $german, new TmdbService('identity-tv-b'), $languageCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'de-DE',
    ]);
    assertSameValue('https://fixture.invalid/identity-tv-b.jpg', $german['icon'] ?? null, 'Base-series records should be isolated by effective TMDB language.');
    assertSameValue(true, $germanResult['lookup'], 'A different TMDB language should perform its own lookup.');

    $tmdbIdentityCache = [];
    $tmdbIdentityA = ['title' => 'Assigned Identity - Episode One', 'episode_num' => '0.0', 'tmdb_id' => 109];
    enrich($plugin, $method, $tmdbIdentityA, new TmdbService('identity-tv-a'), $tmdbIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $tmdbIdentityB = ['title' => 'Assigned Identity - Episode Two', 'episode_num' => '0.1', 'tmdb_id' => 110];
    $tmdbIdentityBResult = enrich($plugin, $method, $tmdbIdentityB, new TmdbService('identity-tv-b'), $tmdbIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://fixture.invalid/identity-tv-b.jpg', $tmdbIdentityB['icon'] ?? null, 'Existing TMDB identities should keep base-series cache entries distinct.');
    assertSameValue(true, $tmdbIdentityBResult['lookup'], 'A different existing TMDB identity should perform its own lookup.');

    $episodeIdentityCache = [];
    $episodeA = ['title' => 'Episode Identity', 'episode_num' => '0.0'];
    enrich($plugin, $method, $episodeA, new TmdbService('identity-tv-a'), $episodeIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $episodeB = ['title' => 'Episode Identity', 'episode_num' => '1.1'];
    $episodeBResult = enrich($plugin, $method, $episodeB, new TmdbService('identity-tv-b'), $episodeIdentityCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://fixture.invalid/identity-tv-b.jpg', $episodeB['icon'] ?? null, 'Unrelated season and episode identities should not share a full-title cache entry.');
    assertSameValue(true, $episodeBResult['lookup'], 'A different season and episode identity should perform its own lookup.');

    $mediaTypeCache = [];
    $movieIdentity = ['title' => 'Media Identity'];
    enrich($plugin, $method, $movieIdentity, new TmdbService('identity-movie-a'), $mediaTypeCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    $tvIdentity = ['title' => 'Media Identity', 'episode_num' => '0.0'];
    $tvIdentityResult = enrich($plugin, $method, $tvIdentity, new TmdbService('identity-tv-b'), $mediaTypeCache, [
        'epg_source_id' => 'source-a',
        'tmdb_language' => 'en-US',
    ]);
    assertSameValue('https://fixture.invalid/identity-tv-b.jpg', $tvIdentity['icon'] ?? null, 'Movie and TV preferences should not share a cache entry.');
    assertSameValue(true, $tvIdentityResult['lookup'], 'A different media preference should perform its own lookup.');

    $bares = [
        'title' => 'Bares für Rares',
        'subtitle' => 'Ein außergewöhnliches Fundstück',
        'episode_num' => '0.0',
        'desc' => 'Horst Lichter begrüßt Menschen, die seltene Fundstücke und Antiquitäten von Experten schätzen lassen.',
        'category' => 'Series',
        'icon' => 'https://provider.invalid/bares-unknown.jpg',
    ];
    $baresCache = [];
    $baresTmdb = new TmdbService('bares');
    enrich($plugin, $method, $bares, $baresTmdb, $baresCache);
    assertSameValue('https://fixture.invalid/bares-backdrop.jpg', $bares['icon'], 'Episodic signals should force the TV landscape backdrop.');
    assertTrueValue(in_array('https://fixture.invalid/bares-poster.jpg', array_column($bares['images'], 'url'), true), 'TV portrait poster should remain in images.');
    assertSameValue($bares['icon'], $bares['images'][0]['url'], 'The selected series landscape should remain the first image.');
    assertSameValue($bares['icon'], $bares['images'][array_key_last($bares['images'])]['url'], 'The selected series landscape should also be the final image.');
    assertSameValue('backdrop', $bares['images'][array_key_last($bares['images'])]['type'], 'The terminal series primary duplicate should retain its image type.');
    assertSameValue(1, $baresTmdb->tvSearches, 'The exact Unicode title should resolve through the TV artwork path.');
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
            'desc' => $description,
            'episode_num' => $episodeNum,
            'category' => 'Series',
        ];
        $ghostsResult = enrich($plugin, $method, $ghosts, $ghostsTmdb, $ghostsCache);

        assertSameValue('https://fixture.invalid/ghosts-backdrop.jpg', $ghosts['icon'] ?? null, $episodeTitle.' should reuse the validated Ghosts series artwork.');
        assertSameValue($index === 0, $ghostsResult['lookup'], $episodeTitle.' should only search TMDB when validating the shared base series.');
        assertSameValue($index > 0, $ghostsResult['cache_hit'], $episodeTitle.' should report reuse of the validated base-series cache.');
    }
    assertSameValue(2, $ghostsTmdb->tvSearches, 'Ghosts should search the full first episode title and then the base series once.');
    assertSameValue(0, $ghostsTmdb->movieSearches, 'Ghosts episode evidence should keep matching on TV.');

    $episodeStill = [
        'title' => 'Ghosts - Der Fahrgeist',
        'desc' => 'Ein Ausflug bringt die Geister durcheinander.',
        'episode_num' => '0.5.',
        'episode_nums' => [
            ['system' => 'xmltv_ns', 'value' => '0.5.'],
        ],
        'category' => 'Series',
        'icon' => 'https://fixture.invalid/ghosts-backdrop.jpg',
        'images' => [
            [
                'url' => 'https://fixture.invalid/ghosts-backdrop.jpg',
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
        'https://fixture.invalid/ghosts-backdrop.jpg',
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
    assertSameValue('https://fixture.invalid/ghosts-backdrop.jpg', $nextEpisodeStill['icon'] ?? null, 'A second episode should keep the trusted series backdrop primary.');
    assertTrueValue(
        in_array('https://image.tmdb.org/t/p/original/ghosts-s01e07.jpg', array_column($nextEpisodeStill['images'] ?? [], 'url'), true),
        'A second episode should retain its exact still as secondary artwork.'
    );
    assertSameValue(1, $episodeTmdb->seasonRequests, 'Episodes in one validated series season should safely reuse the season payload.');

    $trustedProviderEpisode = [
        'title' => 'Ghosts - Hau den Putz',
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

    $persistedEpisodePrimary = [
        'title' => 'Ghosts - Der Fahrgeist',
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
                'url' => 'https://fixture.invalid/ghosts-backdrop.jpg',
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
        'https://fixture.invalid/ghosts-backdrop.jpg',
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
    assertSameValue('https://fixture.invalid/ghosts-backdrop.jpg', $episodeWithoutStill['icon'] ?? null, 'An episode without a still should deterministically fall back to the series backdrop.');
    assertSameValue(1, $episodeTmdb->seasonRequests, 'A missing episode still should reuse the cached season payload.');

    $sameNameGhostsCache = [];
    $sameNameGhostsTmdb = new TmdbService('same-name-ghosts');
    foreach ([2019 => 107, 2021 => 108] as $year => $tmdbId) {
        $sameNameGhosts = [
            'title' => 'Ghosts - Episode '.$year,
            'desc' => 'Comedy series from '.$year.' about a haunted home.',
            'episode_num' => '1.1',
            'category' => 'Series',
        ];
        $sameNameGhostsResult = enrich($plugin, $method, $sameNameGhosts, $sameNameGhostsTmdb, $sameNameGhostsCache);

        assertSameValue(
            'https://fixture.invalid/ghosts-'.$tmdbId.'-backdrop.jpg',
            $sameNameGhosts['icon'] ?? null,
            'Ghosts '.$year.' should use artwork from the series with the matching year.'
        );
        assertSameValue(true, $sameNameGhostsResult['lookup'], 'Ghosts '.$year.' should validate its year-specific series identity.');
    }

    $germanSeries = [
        'title' => 'Die Landarztpraxis',
        'subtitle' => 'Familienbande',
        'episode_num' => '1.42',
        'desc' => 'Isa kämpft in Wiesenkirchen um ihre Familie.',
        'category' => 'Series',
    ];
    $germanSeriesCache = [];
    enrich($plugin, $method, $germanSeries, new TmdbService('german-series'), $germanSeriesCache);
    assertSameValue('https://fixture.invalid/landarztpraxis-backdrop.jpg', $germanSeries['icon'] ?? null, 'An exact German series title should use its TV backdrop.');

    $provider = [
        'title' => 'The Long Walk - Der Todesmarsch',
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
        'desc' => 'A 2020 drama about several lives meeting at a crossroads.',
        'category' => 'Drama',
        'icon' => 'https://provider.invalid/crossroads-unknown.jpg',
    ];
    $ambiguousBefore = $ambiguous;
    $ambiguousCache = [];
    enrich($plugin, $method, $ambiguous, new TmdbService('ambiguous'), $ambiguousCache);
    assertSameValue($ambiguousBefore, $ambiguous, 'Ambiguous TV and movie candidates should leave the programme unchanged.');

    $courtShow = [
        'title' => 'Ulrich Wetzel - Das Strafgericht',
        'subtitle' => 'Der verschwundene Ring',
        'desc' => 'Vor Gericht stehen sich widersprüchliche Aussagen gegenüber.',
        'category' => 'Series',
    ];
    $courtShowBefore = $courtShow;
    $courtShowCache = [];
    enrich($plugin, $method, $courtShow, new TmdbService('ulrich-wetzel'), $courtShowCache);
    assertSameValue($courtShowBefore, $courtShow, 'An unresolved court-show media-type tie should fail closed after full and base-title validation.');

    $boston = [
        'title' => 'Boston',
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
    assertSameValue('https://fixture.invalid/boston-backdrop.jpg', $boston['icon'], 'Boston should replace a portrait-only icon with the validated landscape backdrop.');
    assertSameValue('https://fixture.invalid/boston-backdrop.jpg', $boston['images'][0]['url'] ?? null, 'Boston landscape artwork should be the first images entry.');
    assertTrueValue(in_array('https://provider.invalid/boston-portrait.jpg', array_column($boston['images'], 'url'), true), 'Boston source portrait artwork should remain available after the backdrop.');

    $posterOnly = [
        'title' => 'Poster Only',
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
    assertSameValue('https://fixture.invalid/poster-only.jpg', $posterOnly['icon'] ?? null, 'A poster should be the programme-thumbnail fallback when no landscape artwork exists.');
    assertSameValue(['poster', 'logo', 'poster'], array_column($posterOnly['images'] ?? [], 'type'), 'The poster fallback should bracket secondary artwork so a logo can never become Emby Primary.');
    assertSameValue($posterOnly['icon'], $posterOnly['images'][0]['url'] ?? null, 'The poster fallback should be the first XMLTV image.');
    assertSameValue($posterOnly['icon'], $posterOnly['images'][array_key_last($posterOnly['images'])]['url'] ?? null, 'The poster fallback should also be the final XMLTV image.');

    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'fixture-key';
    $GLOBALS['tmdbTestSettings']->tmdb_language = 'de-DE';
    $titleCard = [
        'title' => 'Backdrop Quality',
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

    echo "TMDB artwork repair tests passed.\n";
    echo json_encode([
        'first_last_boundary_parity' => $benchmarkParity,
        'artwork_quality_evidence' => $artworkQualityEvidence,
        'branch_b_artwork_evidence' => $branchBEvidence,
        'fixture_egress' => count(Http::$calls),
    ], JSON_THROW_ON_ERROR)."\n";
}
