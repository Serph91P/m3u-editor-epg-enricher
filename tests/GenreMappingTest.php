<?php

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

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionClass;

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

    $plugin = new Plugin();
    $reflection = new ReflectionClass($plugin);

    assertTrueValue(
        $reflection->hasMethod('mapToKodiGuideGenre'),
        'Plugin should expose a dedicated Kodi guide genre mapper.'
    );

    $canonicalMapper = $reflection->getMethod('mapToEpgCategory');
    $canonicalMapper->setAccessible(true);
    $kodiMapper = $reflection->getMethod('mapToKodiGuideGenre');
    $kodiMapper->setAccessible(true);

    assertSameValue('Sports', $canonicalMapper->invoke($plugin, 'Basketball', 'movie'), 'Canonical mapping stays compact.');
    assertSameValue('Basketball', $kodiMapper->invoke($plugin, 'Basketball', 'movie'), 'Kodi mapping preserves detailed sports genres.');
    assertSameValue('Sports Event', $kodiMapper->invoke($plugin, 'Sports Event', null), 'Kodi mapping supports issue 347 sports labels.');
    assertSameValue('Public Affairs', $kodiMapper->invoke($plugin, 'Politics', null), 'Kodi mapping supports news sub-genres.');
    assertSameValue('Comedy-Drama', $kodiMapper->invoke($plugin, 'Comedy, Drama', 'movie'), 'Kodi mapping keeps comedy color separate from drama.');
    assertSameValue('Science Fiction', $kodiMapper->invoke($plugin, 'Science Fiction', 'movie'), 'Kodi mapping keeps sci-fi as its own color genre.');
    assertSameValue('Series', $kodiMapper->invoke($plugin, 'Series', 'tv'), 'Kodi mapping supports the Series issue 347 label.');

    $manifest = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $settingIds = [];
    foreach ($manifest['settings'] as $section) {
        foreach ($section['fields'] ?? [] as $field) {
            $settingIds[] = $field['id'];
        }
    }

    assertTrueValue(in_array('map_genres_to_epg_categories', $settingIds, true), 'Emby compatible category option should remain available.');
    assertTrueValue(in_array('map_genres_to_kodi_guide_genres', $settingIds, true), 'Kodi guide genre option should be a separate setting.');

    echo "Genre mapping tests passed.\n";
}
