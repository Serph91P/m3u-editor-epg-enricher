<?php

namespace {
    function app(string $class): object { return $GLOBALS['cancellationServices'][$class] ?? throw new \RuntimeException("Missing service {$class}"); }
    function storage_path(string $path = ''): string { return sys_get_temp_dir().'/epg-enricher-cancellation'; }
}
namespace App\Plugins\Contracts { interface EpgProcessorPluginInterface {} interface HookablePluginInterface {} interface PluginSelectOptionsProviderInterface { public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array; } }
namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}
    class PluginActionResult { public function __construct(public readonly string $status, public readonly bool $success, public readonly string $summary, public readonly array $data = []) {} public static function success(string $s, array $d = []): self { return new self('completed', true, $s, $d); } public static function failure(string $s, array $d = []): self { return new self('failed', false, $s, $d); } public static function cancelled(string $s, array $d = []): self { return new self('cancelled', false, $s, $d); } }
    class PluginExecutionContext { public array $settings = ['enrich_from_tmdb' => true, 'enrich_categories' => true, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'map_genres_to_epg_categories' => true, 'map_genres_to_kodi_guide_genres' => false, 'keyword_category_detection' => true, 'enrich_episode_details' => false]; public array $messages = []; public int $checks = 0; public int $cancelAfter = PHP_INT_MAX; public function cancellationRequested(): bool { return ++$this->checks >= $this->cancelAfter; } public function heartbeat(string $m, ?int $p = null): void { $this->messages[] = $m; } public function info(string $m): void { $this->messages[] = $m; } public function warning(string $m): void { $this->messages[] = $m; } }
}
namespace App\Models { class Values { public function __construct(private array $v) {} public function filter(): self { return $this; } public function unique(): self { return $this; } public function values(): self { return $this; } public function all(): array { return $this->v; } } class Query { public function __construct(private bool $channel) {} public function __call(string $m, array $a): self { return $this; } public function pluck(string $c): Values { return new Values($this->channel ? ['target'] : [1]); } } class Channel { public static function query(): Query { return new Query(false); } } class EpgChannel { public static function query(): Query { return new Query(true); } } class Epg { public string $name = 'Cancellation fixture'; public static function find(int $id): self { return new self(); } } class Playlist {} }
namespace App\Services { class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } } class TmdbService { protected string $language = ''; public function isConfigured(): bool { return true; } } class EpgCacheEnrichmentService { public array $applies = []; public array $snapshotStatuses = []; public array $applyStatuses = ['applied']; public function snapshot(object $c, object $e, int $afterId = 0, int $limit = 500): array { if (($status = array_shift($this->snapshotStatuses)) !== null) return ['status' => $status]; return ['status' => 'ok', 'programmes' => [['id' => 1, 'hash' => 'cancel-hash', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null]; } public function apply(object $c, object $e, array $patches): array { $this->applies[] = compact('patches'); return ['status' => array_shift($this->applyStatuses) ?? 'applied']; } } }
namespace App\Settings { class GeneralSettings { public string $tmdb_language = 'de-DE'; } }
namespace Illuminate\Support\Facades { class Storage { public static function disk(string $n): self { return new self(); } public function makeDirectory(string $p): void {} public function path(string $p): string { return sys_get_temp_dir().'/'.$p; } public function exists(string $p): bool { return false; } public function get(string $p): string { return '{}'; } public function put(string $p, string $v): bool { return true; } public function delete(string $p): bool { return true; } } class Http {} class Log {} }
namespace Tests {
    require_once __DIR__.'/../Plugin.php';
    use App\Plugins\Support\PluginExecutionContext; use App\Services\{EpgCacheEnrichmentService, EpgCacheService, TmdbService}; use App\Settings\GeneralSettings; use AppLocalPlugins\EpgEnricher\Plugin; use ReflectionMethod;
    function same(mixed $e, mixed $a, string $m): void { if ($e !== $a) { fwrite(STDERR, "$m\nExpected: ".var_export($e, true)."\nActual: ".var_export($a, true)."\n"); exit(1); } }
    function execute(array $applyStatuses = ['applied'], array $snapshotStatuses = [], int $cancelAfter = PHP_INT_MAX): array { $host = new EpgCacheEnrichmentService(); $host->applyStatuses = $applyStatuses; $host->snapshotStatuses = $snapshotStatuses; $GLOBALS['cancellationServices'] = [EpgCacheEnrichmentService::class => $host, EpgCacheService::class => new EpgCacheService(), TmdbService::class => new TmdbService(), GeneralSettings::class => new GeneralSettings()]; $context = new PluginExecutionContext(); $context->cancelAfter = $cancelAfter; $method = new ReflectionMethod(new Plugin(), 'doEnrich'); $method->setAccessible(true); return [$method->invoke(new Plugin(), 1, [1], $context), $host, $context]; }
    // This is the migrated cancellation/state contract: the canonical snapshot is
    // immutable to the plugin, and only an accepted host apply may publish a patch.
    [$cancelled, $cancelledHost, $cancelledContext] = execute(['applied'], [], 1);
    same('cancelled', $cancelled->status, 'Cancellation must remain visible at the host snapshot boundary.');
    same(false, $cancelled->success, 'Cancelled work must not report success.');
    same(1, $cancelled->data['channels_targeted'] ?? null, 'Cancellation must retain selected-channel scope.');
    same(0, count($cancelledHost->applies), 'Cancellation must not apply host-owned EPG data.');
    same(0, $cancelled->data['programmes_processed'] ?? 0, 'Cancellation before the first programme must not count processing.');
    same(0, $cancelled->data['programmes_updated'] ?? 0, 'Cancellation must not count unpublished updates.');
    same(['Checking programme details and artwork.'], $cancelledContext->messages, 'Cancellation before the apply pass must report the completed safe check.');

    [$cancelledAtApplyBoundary, $cancelledAtApplyBoundaryHost, $cancelledAtApplyBoundaryContext] = execute(['applied'], [], 2);
    same('cancelled', $cancelledAtApplyBoundary->status, 'Cancellation after the final programme must remain visible at the host apply boundary.');
    same(false, $cancelledAtApplyBoundary->success, 'Cancellation at the host apply boundary must not report success.');
    same(0, count($cancelledAtApplyBoundaryHost->applies), 'Cancellation after programme processing must not publish a host patch.');
    same(0, $cancelledAtApplyBoundary->data['programmes_processed'] ?? 0, 'Cancelled page work must not be committed to the aggregate counters.');
    same(0, $cancelledAtApplyBoundary->data['programmes_updated'] ?? 0, 'Cancellation at the host apply boundary must not count updates.');
    same(['Checking programme details and artwork.'], $cancelledAtApplyBoundaryContext->messages, 'Cancellation at the save boundary must not claim success after the visible check.');

    [$cancelledAfterRetrySnapshot, $cancelledAfterRetrySnapshotHost, $cancelledAfterRetrySnapshotContext] = execute(['stale', 'applied'], [], 3);
    same('cancelled', $cancelledAfterRetrySnapshot->status, 'Cancellation after a stale retry snapshot must remain visible at the second host apply boundary.');
    same(false, $cancelledAfterRetrySnapshot->success, 'Cancellation after a stale retry snapshot must not report success.');
    same(1, count($cancelledAfterRetrySnapshotHost->applies), 'Cancellation after a stale retry snapshot must stop before the retry publication.');
    same(0, $cancelledAfterRetrySnapshot->data['programmes_processed'] ?? 0, 'Cancelled retry work must not be committed to the aggregate counters.');
    same(0, $cancelledAfterRetrySnapshot->data['programmes_updated'] ?? 0, 'Cancelled retry work must not count updates.');
    same(['Checking programme details and artwork.', 'Saving updates.'], $cancelledAfterRetrySnapshotContext->messages, 'Cancelled retry work must not claim success.');

    // Each host rejection retains the previous “no unintended cache/state write”
    // safety property, without recreating the removed direct-cache adapter.
    foreach (['busy', 'unavailable', 'denied', 'invalid_request'] as $outcome) {
        [$result, $host, $context] = execute([$outcome]);
        same('failed', $result->status, "$outcome must fail rather than report completion.");
        same(false, $result->success, "$outcome must fail closed.");
        same(0, $result->data['programmes_processed'] ?? 0, "$outcome must not report an uncommitted page as completed work.");
        same(0, $result->data['programmes_updated'] ?? 0, "$outcome must not count updates.");
        same(1, count($host->applies), "$outcome must not fall back to direct storage.");
        same(1, $host->applies[0]['patches'][0]['id'] ?? null, "$outcome must remain bound to its programme id.");
        same(['Checking programme details and artwork.', 'Saving updates.'], $context->messages, "$outcome must not claim a completed save.");
    }

    [$unavailable, $unavailableHost, $unavailableContext] = execute(['applied'], ['unavailable']);
    same('failed', $unavailable->status, 'An unavailable host snapshot must fail closed.');
    same(false, $unavailable->success, 'An unavailable host snapshot must not report success.');
    same(0, count($unavailableHost->applies), 'An unavailable host snapshot must not attempt a file fallback.');
    same(0, $unavailable->data['programmes_updated'] ?? 0, 'An unavailable host snapshot must not report updates.');
    same([], $unavailableContext->messages, 'A rejected snapshot must not claim processing or publication.');
    $source = (string) file_get_contents(__DIR__.'/../Plugin.php'); foreach (['tmdb-cache.json', 'tmdb-season-cache.json', 'tmdb-images-cache.json'] as $owned) { same(true, str_contains($source, $owned), "Plugin-owned lookup state $owned must remain available."); } foreach (['enrichment-state.json', 'enrichment-checkpoint', 'programmes-', 'metadata.json'] as $forbidden) { same(false, str_contains($source, $forbidden), "Host EPG state marker $forbidden must not return."); }
    $reflection = new \ReflectionClass(Plugin::class); foreach (['saveEpgEnrichmentState', 'saveEnrichmentCheckpoint', 'loadEnrichmentState', 'loadEnrichmentCheckpoint'] as $removed) { same(false, $reflection->hasMethod($removed), "Legacy state method $removed must not be callable."); }
    echo "Enrichment cancellation state tests passed.\n";
}
