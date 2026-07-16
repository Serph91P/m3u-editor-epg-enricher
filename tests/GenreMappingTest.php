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

namespace App\Services {
    class TmdbService
    {
        public function searchTvSeries(string $title, ?int $year = null): array
        {
            return [
                'tmdb_id' => 42,
                'name' => $title,
                'first_air_date' => '2024-01-01',
            ];
        }

        public function getTvSeriesDetails(int $tmdbId): array
        {
            return [
                'tmdb_id' => $tmdbId,
                'name' => 'Profile Matrix Fixture',
                'genres' => 'Basketball',
                'overview' => '',
            ];
        }

        public function getTvAlternativeTitles(int $tmdbId): array
        {
            return [];
        }

        public function searchMovie(string $title, ?int $year = null, bool $tryFallback = true): ?array
        {
            return null;
        }
    }
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

    $fieldsById = [];
    foreach ($manifest['settings'] as $section) {
        foreach ($section['fields'] ?? [] as $field) {
            $fieldsById[$field['id']] = $field;
        }
    }
    $keywordHelp = $fieldsById['keyword_category_detection']['helper_text'] ?? '';
    assertTrueValue(
        str_contains($keywordHelp, 'Emby') && str_contains($keywordHelp, 'Kodi'),
        'Keyword detection help should explain that either existing category mapper enables it.'
    );

    foreach (['jellyfin', 'plex', 'tivimate', 'm3u_tv', 'client_profile'] as $speculativeKey) {
        foreach ($settingIds as $settingId) {
            assertTrueValue(
                ! str_contains($settingId, $speculativeKey),
                "Standard XMLTV clients should not gain a speculative '{$speculativeKey}' setting."
            );
        }
    }

    $profileMapper = $reflection->getMethod('enrichProgrammeFromTmdb');
    $profileMapper->setAccessible(true);
    $profileMatrix = [
        'standard' => [false, false, 'Basketball'],
        'emby' => [true, false, 'Sports'],
        'kodi' => [false, true, 'Basketball'],
        'emby_and_kodi' => [true, true, 'Basketball'],
    ];
    foreach ($profileMatrix as $profile => [$mapEmby, $mapKodi, $expectedCategory]) {
        $programme = ['title' => 'Profile Matrix Fixture'];
        $cache = [];
        $seasonCache = [];
        $imagesCache = [];
        $args = [
            &$programme,
            new \App\Services\TmdbService(),
            &$cache,
            false,
            true,
            false,
            false,
            false,
            $mapEmby,
            $mapKodi,
            false,
            false,
            &$seasonCache,
            &$imagesCache,
            [],
        ];
        $profileMapper->invokeArgs($plugin, $args);
        assertSameValue(
            $expectedCategory,
            $programme['category'] ?? null,
            "Compatibility matrix profile '{$profile}' should select the expected category output."
        );
    }

    $settingsHasher = $reflection->getMethod('computeSettingsHash');
    $settingsHasher->setAccessible(true);
    $profileHashes = [
        'standard' => $settingsHasher->invoke($plugin, []),
        'emby' => $settingsHasher->invoke($plugin, ['map_genres_to_epg_categories' => true]),
        'kodi' => $settingsHasher->invoke($plugin, ['map_genres_to_kodi_guide_genres' => true]),
        'emby_and_kodi' => $settingsHasher->invoke($plugin, [
            'map_genres_to_epg_categories' => true,
            'map_genres_to_kodi_guide_genres' => true,
        ]),
    ];
    assertSameValue(4, count(array_unique($profileHashes)), 'Every compatibility matrix combination should have a distinct settings hash.');
    assertSameValue(
        $profileHashes['emby'],
        $settingsHasher->invoke($plugin, ['map_emby_genres' => true]),
        'The legacy Emby setting should remain hash-compatible with the current key.'
    );

    echo "Genre mapping tests passed.\n";
}
