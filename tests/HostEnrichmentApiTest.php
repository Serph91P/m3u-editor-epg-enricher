<?php

namespace {
    function app(string $class): object
    {
        return $GLOBALS['hostEnrichmentServices'][$class] ?? throw new \RuntimeException("No fixture service for {$class}");
    }

    function now(): object
    {
        return new class
        {
            public function toIso8601String(): string
            {
                return '2026-09-16T12:00:00+00:00';
            }
        };
    }

    function storage_path(string $path = ''): string
    {
        return sys_get_temp_dir().'/epg-enricher-host-api-test';
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
        public function __construct(public readonly string $status, public readonly bool $success, public readonly string $summary, public readonly array $data = []) {}
        public static function success(string $summary, array $data = []): self { return new self('completed', true, $summary, $data); }
        public static function failure(string $summary, array $data = []): self { return new self('failed', false, $summary, $data); }
        public static function cancelled(string $summary, array $data = []): self { return new self('cancelled', false, $summary, $data); }
    }
    class PluginExecutionContext
    {
        public array $settings = ['enrich_from_tmdb' => true, 'overwrite_existing' => false, 'enrich_categories' => true, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'map_genres_to_epg_categories' => true, 'map_genres_to_kodi_guide_genres' => false, 'keyword_category_detection' => true, 'enrich_episode_details' => false];
        public array $messages = [];
        public bool $dryRun = false;
        public int $cancellationChecks = 0;
        public int $cancelAfterChecks = PHP_INT_MAX;
        public function cancellationRequested(): bool { return ++$this->cancellationChecks >= $this->cancelAfterChecks; }
        public function heartbeat(string $message, ?int $progress = null): void { $this->messages[] = $message; }
        public function info(string $message): void { $this->messages[] = $message; }
        public function warning(string $message): void { $this->messages[] = $message; }
    }
}

namespace App\Models {
    class FakeCollection { public function __construct(private array $values) {} public function filter(): self { return $this; } public function unique(): self { return $this; } public function values(): self { return $this; } public function all(): array { return $this->values; } }
    class FakeQuery { public function __call(string $name, array $arguments): self { return $this; } public function pluck(string $column): FakeCollection { return new FakeCollection($column === 'channel_id' ? ['target'] : [1]); } }
    class Channel { public static function query(): FakeQuery { return new FakeQuery(); } }
    class EpgChannel { public static function query(): FakeQuery { return new FakeQuery(); } }
    class Playlist {}
    class Epg { public string $name = 'Fixture EPG'; public function __construct(public int $id = 1) {} public static function find(int $id): self { return new self($id); } }
}

namespace App\Services {
    class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } }
    class TmdbService { protected string $language = ''; public function isConfigured(): bool { return true; } }
    class EpgCacheEnrichmentService
    {
        public array $snapshots = [];
        public array $applies = [];
        public array $applyStatuses = ['applied'];
        public array $snapshotStatuses = [];
        public array $pages = [];
        public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array
        {
            $this->snapshots[] = compact('afterId', 'limit');
            if (($status = array_shift($this->snapshotStatuses)) !== null) {
                return ['status' => $status];
            }
            $page = $this->pages[count($this->snapshots) - 1] ?? null;
            if ($page !== null) {
                return ['status' => 'ok', 'programmes' => $page['programmes'], 'next' => $page['next'] ?? null];
            }
            return ['status' => 'ok', 'programmes' => [['id' => 1, 'hash' => 'hash-'.count($this->snapshots), 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']],], 'next' => null];
        }
        public function apply(object $context, object $epg, array $patches): array
        {
            $this->applies[] = compact('patches');
            return ['status' => array_shift($this->applyStatuses) ?? 'applied'];
        }
    }
}

namespace App\Settings { class GeneralSettings { public string $tmdb_language = ''; } }
namespace Illuminate\Support\Facades { class Storage { public static function disk(string $name): self { return new self(); } public function makeDirectory(string $path): void { @mkdir($this->path($path), 0777, true); } public function path(string $path): string { return sys_get_temp_dir().'/'.$path; } public function exists(string $path): bool { return false; } public function get(string $path): string { return '{}'; } public function put(string $path, string $contents): bool { return true; } } class Http {} class Log {} }

namespace Tests {
    require_once __DIR__.'/../Plugin.php';
    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\EpgCacheEnrichmentService;
    use App\Services\EpgCacheService;
    use App\Services\TmdbService;
    use App\Settings\GeneralSettings;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionMethod;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n"); exit(1); } }
    function runFixture(array $statuses = ['applied'], array $snapshotStatuses = [], int $cancelAfterChecks = PHP_INT_MAX, array $pages = []): array
    {
        $host = new EpgCacheEnrichmentService();
        $host->applyStatuses = $statuses;
        $host->snapshotStatuses = $snapshotStatuses;
        $host->pages = $pages;
        $GLOBALS['hostEnrichmentServices'] = [EpgCacheService::class => new EpgCacheService(), TmdbService::class => new TmdbService(), EpgCacheEnrichmentService::class => $host, GeneralSettings::class => new GeneralSettings()];
        $method = new ReflectionMethod(new Plugin(), 'doEnrich');
        $method->setAccessible(true);
        $context = new PluginExecutionContext();
        $context->cancelAfterChecks = $cancelAfterChecks;
        $result = $method->invoke(new Plugin(), 1, [1], $context);
        return [$result, $host, $context];
    }

    [$result, $host, $context] = runFixture();
    assertSameValue(1, count($host->snapshots), 'Enrichment must request one bounded host snapshot.');
    assertSameValue(['afterId' => 0, 'limit' => 100], $host->snapshots[0], 'Snapshot reads must use the host id cursor and bounded page size.');
    assertSameValue(1, count($host->applies), 'A changed canonical programme must be conditionally applied once.');
    assertSameValue('completed', $result->status, 'Only an accepted host apply may report enrichment success.');
    assertSameValue(1, $result->data['programmes_updated'] ?? null, 'Accepted apply must count the changed programme.');
    assertSameValue(1, $host->applies[0]['patches'][0]['id'] ?? null, 'Patch must preserve the host programme id.');
    assertSameValue('hash-1', $host->applies[0]['patches'][0]['hash'] ?? null, 'Patch must preserve the host programme hash.');
    assertSameValue('Sports', $host->applies[0]['patches'][0]['changes']['category'] ?? null, 'Canonical patch must carry the enriched category.');
    assertSameValue(['Processing canonical host EPG snapshot.', 'Host EPG patch batch published.'], $context->messages, 'Host-boundary heartbeats must remain observable.');

    [$pagedResult, $pagedHost] = runFixture(['applied', 'applied'], [], PHP_INT_MAX, [
        ['programmes' => [['id' => 1, 'hash' => 'page-1', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => 1],
        ['programmes' => [['id' => 2, 'hash' => 'page-2', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null],
    ]);
    assertSameValue([
        ['afterId' => 0, 'limit' => 100],
        ['afterId' => 1, 'limit' => 100],
    ], $pagedHost->snapshots, 'Every host page must advance with its returned integer id cursor.');
    assertSameValue(2, count($pagedHost->applies), 'Every changed page must be submitted through the host API.');
    assertSameValue(2, $pagedResult->data['programmes_updated'] ?? null, 'Accepted pages must contribute to the total update count.');

    [$staleResult, $staleHost] = runFixture(['stale', 'applied']);
    assertSameValue(2, count($staleHost->snapshots), 'A stale page must be re-read exactly once.');
    assertSameValue(2, count($staleHost->applies), 'A stale page must be retried exactly once.');
    assertSameValue(1, $staleResult->data['programmes_updated'] ?? null, 'Only the accepted retry counts as an update.');

    [$legacyResult, $legacyHost] = runFixture(['legacy_cache_read_only']);
    assertSameValue(false, $legacyResult->success, 'Legacy cache read-only outcome must not claim success.');
    assertSameValue(0, $legacyResult->data['programmes_updated'] ?? 0, 'Rejected host apply must not count an update.');
    assertSameValue(1, count($legacyHost->applies), 'Legacy outcome must not use a direct-storage fallback.');

    foreach ([
        'busy' => ['busy'],
        'unavailable' => ['unavailable'],
        'denied' => ['denied'],
        'invalid_request' => ['invalid_request'],
    ] as $status => $statuses) {
        [$rejectedResult, $rejectedHost] = runFixture($statuses);
        assertSameValue(false, $rejectedResult->success, "{$status} must not claim success.");
        assertSameValue(0, $rejectedResult->data['programmes_updated'] ?? 0, "{$status} must not count updates.");
        assertSameValue(1, count($rejectedHost->applies), "{$status} must stop at the host boundary.");
    }

    [$unavailableResult, $unavailableHost] = runFixture(['applied'], ['unavailable']);
    assertSameValue(false, $unavailableResult->success, 'Unavailable or unauthorized host snapshot must not claim success.');
    assertSameValue(0, count($unavailableHost->applies), 'Rejected host snapshot must not attempt a direct-storage fallback.');

    [$cancelledResult, $cancelledHost] = runFixture(['applied'], [], 1);
    assertSameValue('cancelled', $cancelledResult->status, 'Cancellation before host apply must propagate.');
    assertSameValue(0, count($cancelledHost->applies), 'Cancellation must not mutate host-owned EPG data.');

    $manifest = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    assertSameValue('1.0.0', $manifest['api_version'] ?? null, 'The plugin manifest must match the host validator API version.');

    $source = file_get_contents(__DIR__.'/../Plugin.php');
    $tokens = token_get_all($source);
    $pluginCode = '';
    foreach ($tokens as $token) {
        $pluginCode .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? ''
            : (is_array($token) ? $token[1] : $token);
    }
    foreach (['programmes.sqlite', 'new \\PDO', 'sqlite', 'pdo', '.jsonl', 'programmes-', 'metadata.json', 'processDateFile', 'processSqliteStore', 'invalidatePlaylistEpgCaches'] as $forbidden) {
        assertSameValue(false, str_contains(strtolower($pluginCode), strtolower($forbidden)), "Plugin must not retain direct EPG storage adapter marker {$forbidden}.");
    }

    echo "Host enrichment API tests passed.\n";
}
