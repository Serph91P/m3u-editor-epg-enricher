<?php

declare(strict_types=1);

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}
}

namespace App\Plugins\Support {
    class PluginActionResult {}
    class PluginExecutionContext {}
}

namespace App\Models {
    class Channel {}
    class Epg {}
    class EpgChannel {}
    class Playlist {}
}

namespace App\Services {
    class EpgCacheService {}
    class TmdbService {}
}

namespace Illuminate\Support\Facades {
    class Storage {}
}

namespace Carbon {
    class Carbon {}
}

namespace {
    require_once dirname(__DIR__) . '/Plugin.php';

    use AppLocalPlugins\EpgEnricher\Plugin;

    function assertTrue(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function findCandidate(array $candidates, string $source, string $id): ?array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['source'] ?? null) === $source && ($candidate['id'] ?? null) === $id) {
                return $candidate;
            }
        }

        return null;
    }

    function callPrivate(object $instance, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionClass($instance);
        $reflectionMethod = $reflection->getMethod($method);

        return $reflectionMethod->invoke($instance, ...$args);
    }

    function setPrivateProperty(object $instance, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($instance);
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setValue($instance, $value);
    }

    $plugin = new Plugin();

    // Regression: MV provider IDs are movie signals and must not imply episodic TV.
    $movieSignalProgramme = [
        'title' => 'Feature Premiere',
        'episode_num' => 'MV001234',
        'episode_nums' => [
            ['system' => 'dd_progid', 'value' => 'MV001234'],
        ],
    ];

    $movieSignal = callPrivate($plugin, 'detectSeriesSignals', $movieSignalProgramme);
    assertTrue($movieSignal['is_series_episode'] === false, 'MV provider IDs should not mark a programme as episodic TV.');

    // Regression: structured episode_nums (xmltv_ns) should provide season/episode details.
    $xmltvProgramme = [
        'title' => 'SOKO Stuttgart',
        'episode_num' => '',
        'episode_nums' => [
            ['system' => 'onscreen', 'value' => 'Episode 99'],
            ['system' => 'xmltv_ns', 'value' => '0.1.0/1'],
        ],
    ];

    $xmltvSignal = callPrivate($plugin, 'detectSeriesSignals', $xmltvProgramme);
    assertTrue($xmltvSignal['season'] === 1, 'xmltv_ns season should be parsed as season 1.');
    assertTrue($xmltvSignal['episode'] === 2, 'xmltv_ns episode should be parsed as episode 2.');

    // Regression: structural_strict tie-break should prefer TV when episodic signals exist
    // and movie-vs-tv scores are close.
    setPrivateProperty($plugin, 'classifierMode', 'structural');
    $baseClassification = callPrivate($plugin, 'classifyMediaType', [
        'title' => 'SOKO Stuttgart',
        'subtitle' => 'Die Spur',
        'episode_num' => 'MV001234',
        'episode_nums' => [
            ['system' => 'dd_progid', 'value' => 'MV001234'],
        ],
    ], '', ['kids' => 0.0, 'sports' => 0.0, 'news' => 0.0, 'movies' => 0.3]);

    assertTrue($baseClassification['type'] === 'movie', 'Non-strict structural mode should keep close-score MV cases as movie.');

    setPrivateProperty($plugin, 'classifierMode', 'structural_strict');
    $strictClassification = callPrivate($plugin, 'classifyMediaType', [
        'title' => 'SOKO Stuttgart',
        'subtitle' => 'Die Spur',
        'episode_num' => 'MV001234',
        'episode_nums' => [
            ['system' => 'dd_progid', 'value' => 'MV001234'],
        ],
    ], '', ['kids' => 0.0, 'sports' => 0.0, 'news' => 0.0, 'movies' => 0.3]);

    assertTrue($strictClassification['type'] === 'tv', 'Strict mode should flip close-score episodic titles to TV.');

    // Regression: external-id extraction must recognize common imdb/tvdb/tmdb
    // URL patterns and preserve TMDB media hints for tv/movie paths.
    $externalIdProgramme = [
        'urls' => [
            ['system' => 'imdb', 'value' => 'https://www.imdb.com/title/tt0944947/?ref_=fn_al_tt_1'],
            ['system' => 'imdb', 'value' => 'tt0944947'],
            ['system' => 'thetvdb', 'value' => 'https://thetvdb.com/series/121361'],
            ['system' => 'tmdb', 'value' => 'https://www.themoviedb.org/tv/1396-breaking-bad'],
            ['system' => 'tmdb', 'value' => 'https://www.themoviedb.org/movie/603-the-matrix'],
            ['system' => 'tmdb_id', 'value' => '603'],
        ],
    ];

    $externalCandidates = callPrivate($plugin, 'extractExternalIdCandidates', $externalIdProgramme);

    $imdbCandidate = findCandidate($externalCandidates, 'imdb_id', 'tt0944947');
    assertTrue($imdbCandidate !== null, 'IMDb candidate should be extracted from imdb URLs/IDs.');

    $tvdbCandidate = findCandidate($externalCandidates, 'tvdb_id', '121361');
    assertTrue($tvdbCandidate !== null, 'TVDB candidate should be extracted from thetvdb URLs.');

    $tmdbTvCandidate = findCandidate($externalCandidates, 'tmdb_id', '1396');
    assertTrue($tmdbTvCandidate !== null, 'TMDB TV candidate should be extracted from /tv/{id} URLs.');
    assertTrue(($tmdbTvCandidate['media_hint'] ?? null) === 'tv', 'TMDB TV URL should set media_hint=tv.');

    $tmdbMovieCandidate = findCandidate($externalCandidates, 'tmdb_id', '603');
    assertTrue($tmdbMovieCandidate !== null, 'TMDB movie candidate should be extracted from /movie/{id} URLs or numeric IDs.');
    assertTrue(($tmdbMovieCandidate['media_hint'] ?? null) === 'movie', 'TMDB movie URL should set media_hint=movie.');

    $imdbDuplicateCount = count(array_filter(
        $externalCandidates,
        static fn (array $candidate): bool => ($candidate['source'] ?? null) === 'imdb_id' && ($candidate['id'] ?? null) === 'tt0944947'
    ));
    assertTrue($imdbDuplicateCount === 1, 'Duplicate external IDs should be deduplicated.');

    fwrite(STDOUT, "All classifier regression checks passed.\n");
}
