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

    class PluginActionResult {}
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

        public function __construct(private string $scenario) {}

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
                default => null,
            };
        }

        public function getTvSeriesDetails(int $tmdbId): ?array
        {
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
                default => null,
            };
        }

        public function getMovieDetails(int $tmdbId): ?array
        {
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
                default => null,
            };
        }

        public function getTvAlternativeTitles(int $tmdbId): array
        {
            return [];
        }

        public function getMovieAlternativeTitles(int $tmdbId): array
        {
            return match ($this->scenario) {
                'long-walk' => [['title' => 'The Long Walk - Der Todesmarsch', 'iso_3166_1' => 'DE']],
                'illuminati' => [['title' => 'Illuminati', 'iso_3166_1' => 'DE']],
                default => [],
            };
        }
    }
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Services\TmdbService;
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

    function enrich(Plugin $plugin, ReflectionMethod $method, array &$programme, TmdbService $tmdb, array &$cache): array
    {
        $seasonCache = [];
        $imagesCache = [];

        return $method->invokeArgs($plugin, [
            &$programme,
            $tmdb,
            &$cache,
            false,
            true,
            true,
            true,
            true,
            false,
            false,
            false,
            false,
            &$seasonCache,
            &$imagesCache,
        ]);
    }

    $GLOBALS['tmdbTestSettings'] = new GeneralSettings();
    $plugin = new Plugin();
    $reflection = new ReflectionClass($plugin);
    $method = $reflection->getMethod('enrichProgrammeFromTmdb');
    $method->setAccessible(true);

    $longWalk = [
        'title' => 'The Long Walk - Der Todesmarsch',
        'desc' => 'USA 2025. Bei einem Todesmarsch darf niemand stehen bleiben.',
        'category' => 'Movie',
        'icon' => 'https://provider.invalid/unknown.jpg',
        'images' => [
            ['url' => 'https://provider.invalid/unknown.jpg'],
            ['url' => 'https://provider.invalid/unknown.jpg'],
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
        ],
        array_column($longWalk['images'], 'url'),
        'Landscape, poster, unknown and logo ordering should be stable and duplicate-free.'
    );
    assertSameValue('backdrop', $longWalk['images'][0]['type'], 'Primary image should be a landscape backdrop.');
    assertTrueValue(in_array('https://fixture.invalid/movie-poster.jpg', array_column($longWalk['images'], 'url'), true), 'Portrait poster should remain in images.');
    assertTrueValue($longWalkResult['changed'], 'Artwork repair should report a changed programme.');

    $stableLongWalk = $longWalk;
    enrich($plugin, $method, $longWalk, $longWalkTmdb, $longWalkCache);
    assertSameValue($stableLongWalk, $longWalk, 'A second enrichment pass should preserve deterministic image order.');

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
    assertSameValue(2, count($illuminatiCache), 'Lookup cache should separate descriptions that affect identity confidence.');

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
        'title' => 'Provider Programme',
        'desc' => 'Complete provider description.',
        'category' => 'Series',
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
    $providerBefore = $provider;
    $providerCache = [];
    $providerTmdb = new TmdbService('none');
    $providerResult = enrich($plugin, $method, $provider, $providerTmdb, $providerCache);
    assertSameValue($providerBefore, $provider, 'Trusted provider landscape artwork should be preserved.');
    assertSameValue(0, $providerTmdb->tvSearches + $providerTmdb->movieSearches, 'Trusted complete provider metadata should retain the no-op fast path.');
    assertSameValue(false, $providerResult['changed'], 'Provider no-op should not report a change.');

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

    $fetchImages = $reflection->getMethod('fetchTmdbImages');
    $fetchImages->setAccessible(true);
    $imageCache = [];
    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'fixture-key';
    Http::$responses[] = new FakeHttpResponse(false);
    $failedFetch = $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    assertSameValue(null, $failedFetch, 'Transient image failures should return null.');
    assertSameValue([], $imageCache, 'Transient null image responses should not be cached.');

    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    $GLOBALS['tmdbTestSettings']->tmdb_language = 'en-US';
    Http::$responses[] = new FakeHttpResponse(true, ['posters' => [], 'backdrops' => [], 'logos' => []]);
    $fetchImages->invokeArgs($plugin, [99, 'movie', &$imageCache]);
    assertSameValue(
        ['movie:99:de-de', 'movie:99:en-us'],
        array_keys($imageCache),
        'Image cache identity should include the selected TMDB language.'
    );

    echo "TMDB artwork repair tests passed.\n";
}
