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

    fwrite(STDOUT, "All classifier regression checks passed.\n");
}
