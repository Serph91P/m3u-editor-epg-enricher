<?php

namespace {
    function app(string $class): object { return $GLOBALS['outputServices'][$class] ?? throw new \RuntimeException("Missing service {$class}"); }
    function storage_path(string $path = ''): string { return sys_get_temp_dir().'/epg-enricher-output'; }
}
namespace App\Plugins\Contracts { interface EpgProcessorPluginInterface {} interface HookablePluginInterface {} interface PluginSelectOptionsProviderInterface { public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array; } }
namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}
    class PluginActionResult { public function __construct(public readonly string $status, public readonly bool $success, public readonly string $summary, public readonly array $data = []) {} public static function success(string $s, array $d = []): self { return new self('completed', true, $s, $d); } public static function failure(string $s, array $d = []): self { return new self('failed', false, $s, $d); } public static function cancelled(string $s, array $d = []): self { return new self('cancelled', false, $s, $d); } }
    class PluginExecutionContext { public array $settings = ['enrich_from_tmdb' => true, 'enrich_categories' => true, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'map_genres_to_epg_categories' => true, 'map_genres_to_kodi_guide_genres' => false, 'keyword_category_detection' => true, 'enrich_episode_details' => false]; public array $messages = []; public bool $dryRun = false; public function cancellationRequested(): bool { return false; } public function heartbeat(string $m, ?int $p = null): void { $this->messages[] = $m; } public function info(string $m): void { $this->messages[] = $m; } public function warning(string $m): void { $this->messages[] = $m; } }
}
namespace App\Models { class Values { public function __construct(private array $v) {} public function filter(): self { return $this; } public function unique(): self { return $this; } public function values(): self { return $this; } public function all(): array { return $this->v; } } class Query { public function __construct(private bool $channel) {} public function __call(string $m, array $a): self { return $this; } public function pluck(string $c): Values { return new Values($this->channel ? ['target'] : [1]); } } class Channel { public static function query(): Query { return new Query(false); } } class EpgChannel { public static function query(): Query { return new Query(true); } } class Epg { public string $name = 'Output fixture'; public static function find(int $id): self { return new self(); } } class Playlist {} }
namespace App\Services { class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } } class TmdbService { protected string $language = ''; public function isConfigured(): bool { return true; } } class EpgCacheEnrichmentService { public array $applies = []; public array $outcomes = ['applied']; public function snapshot(object $c, object $e, int $afterId = 0, int $limit = 500): array { return ['status' => 'ok', 'programmes' => [['id' => 1, 'hash' => 'output-hash', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null]; } public function apply(object $c, object $e, array $patches): array { $this->applies[] = compact('patches'); return ['status' => array_shift($this->outcomes) ?? 'applied']; } } }
namespace App\Settings { class GeneralSettings { public string $tmdb_language = 'de-DE'; } }
namespace Illuminate\Support\Facades { class Storage { public static function disk(string $n): self { return new self(); } public function makeDirectory(string $p): void {} public function path(string $p): string { return sys_get_temp_dir().'/'.$p; } public function exists(string $p): bool { return false; } public function get(string $p): string { return '{}'; } public function put(string $p, string $v): bool { return true; } } class Http {} class Log {} }
namespace Tests {
    require_once __DIR__.'/../Plugin.php';
    use App\Plugins\Support\PluginExecutionContext; use App\Services\{EpgCacheEnrichmentService, EpgCacheService, TmdbService}; use App\Settings\GeneralSettings; use AppLocalPlugins\EpgEnricher\Plugin; use ReflectionMethod;
    function same(mixed $e, mixed $a, string $m): void { if ($e !== $a) { fwrite(STDERR, "$m\nExpected: ".var_export($e, true)."\nActual: ".var_export($a, true)."\n"); exit(1); } }
    function execute(array $outcomes): array { $host = new EpgCacheEnrichmentService(); $host->outcomes = $outcomes; $GLOBALS['outputServices'] = [EpgCacheEnrichmentService::class => $host, EpgCacheService::class => new EpgCacheService(), TmdbService::class => new TmdbService(), GeneralSettings::class => new GeneralSettings()]; $context = new PluginExecutionContext(); $method = new ReflectionMethod(new Plugin(), 'doEnrich'); $method->setAccessible(true); return [$method->invoke(new Plugin(), 1, [1], $context), $host, $context]; }
    // Replaces the prior post-enrichment XMLTV-cache assertions.  The host owns
    // serialization now, so publication is observed at its conditional-apply boundary.
    [$accepted, $acceptedHost, $acceptedContext] = execute(['applied']);
    same('completed', $accepted->status, 'The retained output contract must complete only after host publication.');
    same(true, $accepted->success, 'An accepted host publication must be successful.');
    same(1, $accepted->data['programmes_processed'] ?? null, 'The selected canonical programme must be processed once.');
    same(1, $accepted->data['programmes_updated'] ?? null, 'Only an accepted host apply may count a programme update.');
    same(1, count($acceptedHost->applies), 'An affected selected programme must submit one host patch batch.');
    same(1, $acceptedHost->applies[0]['patches'][0]['id'] ?? null, 'The host programme id must remain attached to its output patch.');
    same('output-hash', $acceptedHost->applies[0]['patches'][0]['hash'] ?? null, 'The host content hash must remain attached to its output patch.');
    same('Sports', $acceptedHost->applies[0]['patches'][0]['changes']['category'] ?? null, 'The enriched output category must be published through the host.');
    same(['Checking programme details and artwork.', 'Checking programme details and artwork.', 'Saving updates.'], $acceptedContext->messages, 'End-user messages must cover evidence, apply, and accepted output work.');

    // A host no-op replaces the old “unchanged playlist output stays cached” case:
    // no direct XMLTV/cache action is available to the plugin.
    [$noop, $noopHost, $noopContext] = execute(['noop']);
    same('completed', $noop->status, 'A host no-op remains a completed non-mutation.');
    same(true, $noop->success, 'A host no-op must remain a successful scan.');
    same(1, $noop->data['programmes_processed'] ?? null, 'A no-op still scans the canonical selected programme.');
    same(0, $noop->data['programmes_updated'] ?? null, 'A no-op must not advertise an XMLTV/output mutation.');
    same(1, count($noopHost->applies), 'The host, rather than a direct output cache, must decide a no-op.');
    same(1, $noopHost->applies[0]['patches'][0]['id'] ?? null, 'A no-op remains bound to the host programme id.');
    same(['Checking programme details and artwork.', 'Checking programme details and artwork.', 'Saving updates.'], $noopContext->messages, 'No-op output must report evidence and the attempted save without claiming publication.');
    $changes = (new ReflectionMethod(Plugin::class, 'canonicalHostChanges'))->invoke(new Plugin(), ['icon' => 'https://source.invalid/icon.png', 'images' => []], ['icon' => 'https://source.invalid/icon.png', 'images' => [['url' => 'https://fixture.invalid/poster.jpg', 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 10], ['url' => 'https://fixture.invalid/backdrop.jpg', 'type' => 'backdrop', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 20], ['url' => 'https://fixture.invalid/still.jpg', 'type' => 'screenshot', 'width' => 1280, 'height' => 720, 'orient' => 'L', 'size' => 30], ['url' => 'https://fixture.invalid/logo.png', 'type' => 'logo', 'width' => 800, 'height' => 300, 'orient' => 'L', 'size' => 40]]]);
    same(['poster', 'fanart', 'banner', 'logo'], array_column($changes['images'] ?? [], 'type'), 'Poster, backdrop, still and logo retain separate canonical roles.');
    same(['https://fixture.invalid/poster.jpg', 'https://fixture.invalid/backdrop.jpg', 'https://fixture.invalid/still.jpg', 'https://fixture.invalid/logo.png'], array_column($changes['images'] ?? [], 'url'), 'Role-specific output URLs must be retained.');
    same([1000, 1920, 1280, 800], array_column($changes['images'] ?? [], 'width'), 'Artwork widths must survive canonical serialization.');
    same([1500, 1080, 720, 300], array_column($changes['images'] ?? [], 'height'), 'Artwork heights must survive canonical serialization.');
    same(['P', 'L', 'L', 'L'], array_column($changes['images'] ?? [], 'orient'), 'Artwork orientations must remain role-specific.');
    same([10, 20, 30, 40], array_column($changes['images'] ?? [], 'size'), 'Artwork sizes must survive canonical serialization.');
    same(false, isset($changes['icon']), 'An unchanged generic icon must not be overwritten by artwork roles.');
    $trustedLandscape = new ReflectionMethod(Plugin::class, 'hasTrustedLandscapeIcon');
    $trustedLandscape->setAccessible(true);
    foreach (['fanart', 'banner'] as $canonicalRole) {
        $hostRoundTrip = [
            'icon' => "https://image.tmdb.org/t/p/w1280/host-{$canonicalRole}.jpg",
            'images' => [[
                'url' => "https://image.tmdb.org/t/p/w1280/host-{$canonicalRole}.jpg",
                'type' => $canonicalRole,
                'width' => 1280,
                'height' => 720,
                'orient' => 'L',
                'size' => 1,
            ]],
        ];
        same(true, $trustedLandscape->invoke(new Plugin(), $hostRoundTrip), "A canonical host round-trip of a TMDB {$canonicalRole} role must remain trusted and avoid repeated artwork repair.");
    }
    $untrustedBannerRoundTrip = $hostRoundTrip;
    $untrustedBannerRoundTrip['icon'] = 'https://untrusted.invalid/host-banner.jpg';
    $untrustedBannerRoundTrip['images'][0]['url'] = $untrustedBannerRoundTrip['icon'];
    same(false, $trustedLandscape->invoke(new Plugin(), $untrustedBannerRoundTrip), 'A source-less banner outside the verified TMDB host must not become trusted.');
    $tokens = token_get_all((string) file_get_contents(__DIR__.'/../Plugin.php')); $code = ''; foreach ($tokens as $token) { $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token); } foreach (['requestPlaylistXmltv', 'clearPlaylistEpgCacheFile', 'playlist-epg-files'] as $forbidden) { same(false, str_contains($code, $forbidden), "Direct output adapter $forbidden must not return."); }
    echo "Emby output contract tests passed.\n";
}
