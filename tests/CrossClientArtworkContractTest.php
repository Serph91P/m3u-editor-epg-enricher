<?php

namespace {
    function storage_path(string $path = ''): string { return '/dev/null'; }
    function app(string $class): object { return new \App\Settings\GeneralSettings(); }
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
    class Log { public static function warning(string $message, array $context = []): void {} }
}

namespace App\Services {
    class TmdbService {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use AppLocalPlugins\EpgEnricher\Plugin;
    use DOMDocument;
    use DOMXPath;
    use ReflectionMethod;

    function crossClientAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    function serializeProgrammeIcons(array $programme): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElement('programme');
        $document->appendChild($root);
        foreach (is_array($programme['images'] ?? null) ? $programme['images'] : [] as $image) {
            if (! is_array($image) || ! is_string($image['url'] ?? null) || $image['url'] === '') {
                continue;
            }
            $icon = $document->createElement('icon');
            $icon->setAttribute('src', $image['url']);
            foreach (['type', 'width', 'height', 'orient', 'size'] as $attribute) {
                if (is_scalar($image[$attribute] ?? null)) {
                    $icon->setAttribute($attribute, (string) $image[$attribute]);
                }
            }
            $root->appendChild($icon);
        }

        return (string) $document->saveXML();
    }

    /** @return list<array<string, string>> */
    function parseProgrammeIcons(string $xml): array
    {
        $document = new DOMDocument();
        crossClientAssert($document->loadXML($xml, LIBXML_NONET), 'The local XMLTV icon fixture must parse without network access.');
        $nodes = (new DOMXPath($document))->query('/programme/icon');
        $icons = [];
        foreach ($nodes ?: [] as $node) {
            $icon = [];
            foreach ($node->attributes ?? [] as $attribute) {
                $icon[$attribute->name] = $attribute->value;
            }
            $icons[] = $icon;
        }

        return $icons;
    }

    function firstOnlyPrimary(array $icons): ?string
    {
        return $icons[0]['src'] ?? null;
    }

    function lastWinsPrimary(array $icons): ?string
    {
        return $icons === [] ? null : ($icons[array_key_last($icons)]['src'] ?? null);
    }

    /** @return array<string, list<string>> */
    function roleAwareImages(array $icons): array
    {
        $roles = [];
        foreach ($icons as $icon) {
            $type = strtolower($icon['type'] ?? '');
            if (! in_array($type, ['poster', 'backdrop', 'fanart', 'screenshot', 'still', 'logo'], true)) {
                continue;
            }
            $roles[$type][] = $icon['src'];
        }

        return $roles;
    }

    function legacyBoundaryIsSafe(array $icons, string $expectedPrimary): bool
    {
        return firstOnlyPrimary($icons) === $expectedPrimary
            && lastWinsPrimary($icons) === $expectedPrimary;
    }

    function artwork(string $url, string $type, string $source, string $scope): array
    {
        $landscape = ! in_array($type, ['poster', 'logo'], true);

        return [
            'url' => $url,
            'type' => $type,
            'source' => $source,
            'scope' => $scope,
            'orient' => $type === 'poster' ? 'P' : 'L',
            'width' => $type === 'poster' ? 500 : ($type === 'logo' ? 800 : 1920),
            'height' => $type === 'poster' ? 750 : ($type === 'logo' ? 300 : 1080),
            'size' => $landscape ? 1 : 2,
        ] + ($source === 'tmdb' && in_array($type, ['backdrop', 'fanart'], true)
            ? ['artwork_quality' => 'tmdb_vote_evidence']
            : []);
    }

    $brokenProgramme = [
        'icon' => 'fixture://safe-landscape',
        'images' => [
            artwork('fixture://safe-landscape', 'backdrop', 'tmdb', 'programme'),
            artwork('fixture://channel-logo', 'logo', 'provider', 'programme'),
        ],
    ];
    crossClientAssert(
        ! legacyBoundaryIsSafe(parseProgrammeIcons(serializeProgrammeIcons($brokenProgramme)), $brokenProgramme['icon']),
        'The contract test must reject an intentionally broken terminal-logo boundary fixture.'
    );

    $cases = [
        'TMDB backdrop primary' => [
            'programme' => [
                'icon' => 'fixture://tmdb-backdrop',
                'images' => [
                    artwork('fixture://tmdb-backdrop', 'backdrop', 'tmdb', 'programme'),
                    artwork('fixture://poster-a', 'poster', 'tmdb', 'programme'),
                    artwork('fixture://logo-a', 'logo', 'tmdb', 'programme'),
                ],
            ],
            'roles' => ['backdrop', 'poster', 'logo'],
        ],
        'TMDB fanart primary' => [
            'programme' => [
                'icon' => 'fixture://tmdb-fanart',
                'images' => [
                    artwork('fixture://tmdb-fanart', 'fanart', 'tmdb', 'programme'),
                    artwork('fixture://poster-b', 'poster', 'tmdb', 'programme'),
                    artwork('fixture://logo-b', 'logo', 'tmdb', 'programme'),
                ],
            ],
            'roles' => ['fanart', 'poster', 'logo'],
        ],
        'Exact episode still secondary' => [
            'programme' => [
                'icon' => 'fixture://series-backdrop',
                'images' => [
                    artwork('fixture://episode-still', 'screenshot', 'tmdb', 'episode'),
                    artwork('fixture://series-backdrop', 'backdrop', 'tmdb', 'series'),
                    artwork('fixture://poster-c', 'poster', 'tmdb', 'programme'),
                    artwork('fixture://logo-c', 'logo', 'tmdb', 'programme'),
                ],
            ],
            'roles' => ['backdrop', 'screenshot', 'poster', 'logo'],
        ],
        'Poster-only fallback' => [
            'programme' => [
                'images' => [
                    artwork('fixture://logo-d', 'logo', 'tmdb', 'programme'),
                    artwork('fixture://poster-d', 'poster', 'tmdb', 'programme'),
                ],
            ],
            'roles' => ['poster', 'logo'],
        ],
        'Trusted provider landscape retained' => [
            'programme' => [
                'icon' => 'fixture://provider-landscape',
                'images' => [
                    artwork('fixture://tmdb-backdrop-e', 'backdrop', 'tmdb', 'programme'),
                    artwork('fixture://provider-landscape', 'fanart', 'provider', 'programme'),
                    artwork('fixture://poster-e', 'poster', 'tmdb', 'programme'),
                    artwork('fixture://logo-e', 'logo', 'provider', 'programme'),
                ],
            ],
            'roles' => ['fanart', 'backdrop', 'poster', 'logo'],
        ],
    ];

    $plugin = new Plugin();
    $finalize = new ReflectionMethod($plugin, 'finalizeImageSerialization');
    $finalize->setAccessible(true);
    foreach ($cases as $label => $case) {
        $programme = $case['programme'];
        $providerPrimary = $label === 'Trusted provider landscape retained' ? $programme['icon'] : null;
        $finalize->invokeArgs($plugin, [&$programme, true, false]);
        $primary = $programme['icon'] ?? null;
        crossClientAssert(is_string($primary) && $primary !== '', $label.' must select a legacy primary.');
        crossClientAssert(($programme['images'][0]['url'] ?? null) === $primary, $label.' must place the primary at the producer image-list start.');
        crossClientAssert(($programme['images'][array_key_last($programme['images'])]['url'] ?? null) === $primary, $label.' must place the primary at the producer image-list end.');
        $icons = parseProgrammeIcons(serializeProgrammeIcons($programme));
        crossClientAssert(legacyBoundaryIsSafe($icons, $primary), $label.' must expose the identical primary to first-only and last-wins clients.');
        $roleAware = roleAwareImages($icons);
        foreach ($case['roles'] as $role) {
            crossClientAssert(isset($roleAware[$role]), $label.' must preserve the typed '.$role.' role.');
        }
        if ($label === 'Exact episode still secondary') {
            crossClientAssert($primary === 'fixture://series-backdrop', 'An exact episode still must not replace the series landscape primary.');
            crossClientAssert(in_array('fixture://episode-still', $roleAware['screenshot'] ?? [], true), 'The exact episode still must survive as a typed screenshot.');
        }
        if ($providerPrimary !== null) {
            crossClientAssert($primary === $providerPrimary, 'Overwrite disabled must retain trusted provider landscape artwork.');
        }
    }

    fwrite(STDOUT, "Cross-client artwork contract tests passed: broken fixture rejected; 5 producer cases passed.\n");
}
