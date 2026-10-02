<?php

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}
    interface PluginSelectOptionsProviderInterface {}
}

namespace App\Plugins\Support {
    class PluginActionResult {}
    class PluginExecutionContext {}
    class PluginSelectOptionsContext {}
}

namespace App\Services {
    class TmdbService
    {
        public function __construct(
            private readonly string $mediaType,
            private readonly array $details,
        ) {}

        public function searchTvSeriesCandidates(string $title, ?int $year, int $limit): array
        {
            if ($this->mediaType !== 'tv') {
                return [];
            }

            return [[
                'tmdb_id' => 101,
                'name' => $title,
                'original_name' => $title,
                'first_air_date' => '2024-01-01',
                'overview' => 'Synthetic television fixture.',
            ]];
        }

        public function searchMovieCandidates(string $title, ?int $year, int $limit): array
        {
            if ($this->mediaType !== 'movie') {
                return [];
            }

            return [[
                'tmdb_id' => 202,
                'title' => $title,
                'original_title' => $title,
                'release_date' => '2024-01-01',
                'overview' => 'Synthetic movie fixture.',
            ]];
        }

        public function getTvSeriesDetails(int $tmdbId): array
        {
            return $this->details;
        }

        public function getMovieDetails(int $tmdbId): array
        {
            return $this->details;
        }
    }
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Services\TmdbService;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionClass;
    use ReflectionMethod;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    function tvDetails(array $overrides = []): array
    {
        return array_replace([
            'tmdb_id' => 101,
            'tvdb_id' => 301,
            'imdb_id' => 'tt0000101',
            'name' => 'Synthetic Series',
            'original_name' => 'Synthetic Series',
            'overview' => 'Synthetic television fixture.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/tv-poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/tv-backdrop.jpg',
            'first_air_date' => '2024-01-01',
            'genres' => 'Drama',
            'vote_average' => 7.5,
            'vote_count' => 50,
            'status' => 'Returning Series',
            'number_of_seasons' => 2,
            'number_of_episodes' => 12,
            'cast' => 'Actor One, Actor Two',
            'director' => 'Director One',
            'youtube_trailer' => null,
            'logo_url' => 'https://image.tmdb.org/t/p/w500/tv-logo.png',
            'cast_list' => [[
                'id' => 401,
                'name' => 'Actor One',
                'character' => 'Lead',
                'photo' => 'https://image.tmdb.org/t/p/w185/actor-one.jpg',
            ]],
            'certification' => 'TV-14',
            'networks' => [[
                'id' => 501,
                'name' => 'Synthetic Network',
                'logo' => 'https://image.tmdb.org/t/p/w300/network.png',
            ]],
            'recommendations' => [[
                'tmdb_id' => 102,
                'title' => 'Related Synthetic Series',
                'poster_url' => 'https://image.tmdb.org/t/p/w342/related-tv.jpg',
                'media_type' => 'tv',
            ]],
        ], $overrides);
    }

    function movieDetails(array $overrides = []): array
    {
        return array_replace([
            'tmdb_id' => 202,
            'imdb_id' => 'tt0000202',
            'title' => 'Synthetic Movie',
            'original_title' => 'Synthetic Movie',
            'overview' => 'Synthetic movie fixture.',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/movie-poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/original/movie-backdrop.jpg',
            'release_date' => '2024-01-01',
            'genres' => 'Adventure',
            'vote_average' => 8,
            'vote_count' => 75,
            'runtime' => 110,
            'status' => 'Released',
            'cast' => ['Actor One', 'Actor Two'],
            'director' => ['Director One'],
            'youtube_trailer' => null,
            'logo_url' => null,
            'cast_list' => [[
                'id' => 402,
                'name' => 'Actor Two',
                'character' => 'Lead',
                'photo' => null,
            ]],
            'certification' => 'PG-13',
            'studios' => [[
                'id' => 502,
                'name' => 'Synthetic Studio',
                'logo' => null,
            ]],
            'recommendations' => [[
                'tmdb_id' => 203,
                'title' => 'Related Synthetic Movie',
                'poster_url' => null,
                'media_type' => 'movie',
            ]],
        ], $overrides);
    }

    function validatedSearch(Plugin $plugin, ReflectionMethod $method, string $mediaType, array $details): ?array
    {
        return $method->invokeArgs($plugin, [
            new TmdbService($mediaType, $details),
            $mediaType === 'tv' ? 'Synthetic Series' : 'Synthetic Movie',
            $mediaType,
            2024,
        ]);
    }

    $plugin = new Plugin();
    $reflection = new ReflectionClass($plugin);
    $search = $reflection->getMethod('searchTmdbWithValidation');
    $search->setAccessible(true);

    foreach (['tv' => tvDetails(), 'movie' => movieDetails()] as $mediaType => $details) {
        $result = validatedSearch($plugin, $search, $mediaType, $details);
        assertSameValue(true, is_array($result), "The known host {$mediaType} details DTO must be accepted.");
        assertSameValue(false, array_key_exists('logo_url', $result), 'Discarded host-only logo_url must not enter the plugin cache shape.');
        assertSameValue(false, array_key_exists('cast_list', $result), 'Discarded host-only cast_list must not enter the plugin cache shape.');
        assertSameValue(false, array_key_exists('certification', $result), 'Discarded host-only certification must not enter the plugin cache shape.');
        assertSameValue(false, array_key_exists('recommendations', $result), 'Discarded host-only recommendations must not enter the plugin cache shape.');
        assertSameValue(false, array_key_exists($mediaType === 'tv' ? 'networks' : 'studios', $result), 'Discarded host-only companies must not enter the plugin cache shape.');
    }

    $baselineMovieDetails = movieDetails();
    unset($baselineMovieDetails['logo_url'], $baselineMovieDetails['cast_list'], $baselineMovieDetails['certification'], $baselineMovieDetails['studios'], $baselineMovieDetails['recommendations']);
    assertSameValue(true, is_array(validatedSearch($plugin, $search, 'movie', $baselineMovieDetails)), 'The previous exact plugin DTO shape must remain compatible.');

    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['unexpected_field' => 'reject'])), 'Unknown host details keys must still be rejected.');
    $missingRequired = tvDetails();
    unset($missingRequired['genres']);
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', $missingRequired), 'Missing required plugin details fields must still be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['runtime' => '110'])), 'Bad canonical field types must still be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['logo_url' => 'https://fixture.invalid/logo.png'])), 'Untrusted host logo URLs must still be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['cast_list' => 'Actor One'])), 'Bad host extra types must still be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['studios' => []])), 'Movie-only host fields must be rejected for TV details.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['networks' => []])), 'TV-only host fields must be rejected for movie details.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['networks' => [['id' => 501, 'name' => 'Synthetic Network', 'logo' => 'https://fixture.invalid/network.png']]])), 'Untrusted company logo URLs must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['studios' => [['id' => 502, 'name' => 'Synthetic Studio']]])), 'Incomplete company entries must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['networks' => [['id' => 501, 'name' => 'Synthetic Network', 'logo' => null, 'country' => 'US']]])), 'Unknown company entry keys must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['recommendations' => [['tmdb_id' => 102, 'title' => 'Related Synthetic Series', 'poster_url' => 'https://fixture.invalid/poster.jpg', 'media_type' => 'tv']]])), 'Untrusted recommendation poster URLs must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['recommendations' => [['tmdb_id' => 203, 'title' => 'Related Synthetic Movie', 'poster_url' => null, 'media_type' => 'tv']]])), 'Recommendation media types must match the detail DTO.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['recommendations' => [['tmdb_id' => 203, 'title' => 'Related Synthetic Movie', 'poster_url' => null, 'media_type' => 'movie', 'overview' => 'reject']]])), 'Unknown recommendation entry keys must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'tv', tvDetails(['cast_list' => [['id' => 401, 'name' => 'Actor One', 'character' => 'Lead', 'photo' => 'https://fixture.invalid/photo.jpg']]])), 'Untrusted cast photo URLs must be rejected.');
    assertSameValue(null, validatedSearch($plugin, $search, 'movie', movieDetails(['tmdb_id' => 999])), 'TMDB identity mismatches must still be rejected.');

    echo "TMDB details shape compatibility tests passed.\n";
}
