<?php

namespace {
    function app(string $class): object
    {
        return $GLOBALS['services'][$class] ?? throw new \RuntimeException("Missing service {$class}");
    }

    function storage_path(string $path = ''): string
    {
        return sys_get_temp_dir().'/epg-enricher-heartbeat';
    }
}

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}
    interface PluginSelectOptionsProviderInterface { public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array; }
}

namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}
    class PluginActionResult {
        public function __construct(public readonly string $status, public readonly bool $success, public readonly string $summary, public readonly array $data = []) {}
        public static function success(string $summary, array $data = []): self { return new self('completed', true, $summary, $data); }
        public static function failure(string $summary, array $data = []): self { return new self('failed', false, $summary, $data); }
        public static function cancelled(string $summary, array $data = []): self { return new self('cancelled', false, $summary, $data); }
    }
    class PluginExecutionContext {
        public array $settings = ['enrich_from_tmdb' => true, 'overwrite_existing' => false, 'enrich_categories' => true, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'map_genres_to_epg_categories' => true, 'map_genres_to_kodi_guide_genres' => false, 'keyword_category_detection' => true, 'enrich_episode_details' => false];
        public array $heartbeats = [];
        public int $checks = 0;
        public int $cancelAfter = PHP_INT_MAX;
        public function cancellationRequested(): bool { return ++$this->checks >= $this->cancelAfter; }
        public function heartbeat(string $message, ?int $progress = null): void { $this->heartbeats[] = ['message' => $message, 'progress' => $progress]; }
        public function info(string $message): void { $this->heartbeat($message); }
        public function warning(string $message): void { $this->heartbeat($message); }
    }
}

namespace App\Models {
    class Values { public function __construct(private array $values) {} public function filter(): self { return $this; } public function unique(): self { return $this; } public function values(): self { return $this; } public function all(): array { return $this->values; } }
    class Query { public function __construct(private bool $channels) {} public function __call(string $method, array $arguments): self { return $this; } public function pluck(string $column): Values { return new Values($this->channels ? ['target'] : [1]); } }
    class Channel { public static function query(): Query { return new Query(false); } }
    class EpgChannel { public static function query(): Query { return new Query(true); } }
    class Epg { public string $name = 'Heartbeat fixture'; public static function find(int $id): self { return new self(); } }
    class Playlist {}
}

namespace App\Services {
    class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } }
    class TmdbService { protected string $language = ''; public function isConfigured(): bool { return true; } }
    class EpgCacheEnrichmentService {
        public array $pages = [];
        public array $snapshots = [];
        public array $applies = [];
        public array $outcomes = ['applied'];
        public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array {
            $this->snapshots[] = compact('afterId', 'limit');
            $page = $this->pages[count($this->snapshots) - 1] ?? ['programmes' => [['id' => 1, 'hash' => 'h1', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null];
            return ['status' => 'ok'] + $page;
        }
        public function apply(object $context, object $epg, array $patches): array { $this->applies[] = compact('patches'); return ['status' => array_shift($this->outcomes) ?? 'applied']; }
    }
}

namespace App\Settings { class GeneralSettings { public string $tmdb_language = ''; } }
namespace Illuminate\Support\Facades {
    class Storage { public static function disk(string $name): self { return new self(); } public function makeDirectory(string $path): void {} public function path(string $path): string { return sys_get_temp_dir().'/'.$path; } public function exists(string $path): bool { return false; } public function get(string $path): string { return '{}'; } public function put(string $path, string $contents): bool { return true; } }
    class Http {} class Log {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';
    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\{EpgCacheEnrichmentService, EpgCacheService, TmdbService};
    use App\Settings\GeneralSettings;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionMethod;

    function same(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) { fwrite(STDERR, "$message\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n"); exit(1); } }
    function run(array $pages, array $outcomes = ['applied'], int $cancelAfter = PHP_INT_MAX, string $tmdbLanguage = ''): array {
        $host = new EpgCacheEnrichmentService(); $host->pages = $pages; $host->outcomes = $outcomes;
        $tmdb = new TmdbService();
        $GLOBALS['services'] = [EpgCacheEnrichmentService::class => $host, EpgCacheService::class => new EpgCacheService(), TmdbService::class => $tmdb, GeneralSettings::class => new GeneralSettings()];
        $context = new PluginExecutionContext(); $context->cancelAfter = $cancelAfter; $context->settings['tmdb_language'] = $tmdbLanguage;
        $method = new ReflectionMethod(new Plugin(), 'doEnrich'); $method->setAccessible(true);
        return [$method->invoke(new Plugin(), 1, [1], $context), $host, $context, $tmdb];
    }

    $rows = array_map(fn (int $n): array => ['id' => $n, 'hash' => "h$n", 'programme' => ['channel' => 'target', 'title' => '']], range(1, 500));
    $pages = array_map(fn (array $page, int $index): array => ['programmes' => $page, 'next' => $index === 4 ? null : ($index + 1) * 100], array_chunk($rows, 100), range(0, 4));
    [$large, $largeHost, $largeContext] = run($pages);
    same('completed', $large->status, 'A bounded host scan must complete.');
    same(500, $large->data['programmes_processed'] ?? null, 'The 500-programme regression fixture must retain every canonical row.');
    same(0, $large->data['tmdb_lookups'] ?? null, 'Untitled canonical rows must not create TMDB requests.');
    same(5, count($largeHost->snapshots), 'The host snapshot cursor must retain the five-page lifecycle.');
    same(['afterId' => 0, 'limit' => 100], $largeHost->snapshots[0], 'The first snapshot must retain its bounded limit.');
    same(100, $largeHost->snapshots[1]['afterId'] ?? null, 'The second snapshot must use the returned id cursor.');
    same(5, count($largeContext->heartbeats), 'Each retained page must emit its heartbeat.');

    $changePage = [['programmes' => [['id' => 1, 'hash' => 'h1', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null], ['programmes' => [['id' => 1, 'hash' => 'h2', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => null]];
    [$stale, $staleHost, $staleContext] = run($changePage, ['stale', 'applied']);
    same('completed', $stale->status, 'A stale host snapshot must retain one bounded retry.');
    same(2, count($staleHost->snapshots), 'Stale handling must re-snapshot once.');
    same(2, count($staleHost->applies), 'Stale handling must re-apply once without a file fallback.');
    same(1, $stale->data['programmes_updated'] ?? null, 'Only an accepted retry may count a mutation.');
    same(['Processing canonical host EPG snapshot.', 'Host EPG patch batch published.'], array_column($staleContext->heartbeats, 'message'), 'The published heartbeat must remain after accepted host apply only.');

    [$cancelled, $cancelledHost, $cancelledContext] = run($changePage, ['applied'], 1);
    same('cancelled', $cancelled->status, 'Abort protection must remain observable during canonical snapshot processing.');
    same(0, count($cancelledHost->applies), 'Aborted work must not conditionally apply a partial patch batch.');
    same(0, $cancelled->data['programmes_updated'] ?? 0, 'Aborted work must not report unpublished updates.');
    same(['Processing canonical host EPG snapshot.'], array_column($cancelledContext->heartbeats, 'message'), 'Abort must preserve the processing heartbeat but not a publish heartbeat.');

    $cursorPages = [
        ['programmes' => [['id' => 1, 'hash' => 'h1', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => 1],
        ['programmes' => [['id' => 1, 'hash' => 'h2', 'programme' => ['channel' => 'target', 'title' => 'Bundesliga']]], 'next' => 2],
        ['programmes' => [['id' => 2, 'hash' => 'h3', 'programme' => ['channel' => 'target', 'title' => '']]], 'next' => null],
    ];
    [$cursorResult, $cursorHost] = run($cursorPages, ['stale', 'applied']);
    same('completed', $cursorResult->status, 'A stale first page must continue from the accepted retry snapshot.');
    same(2, $cursorHost->snapshots[2]['afterId'] ?? null, 'The page after a stale retry must use the retry cursor, not the stale cursor.');
    same(false, in_array(1, array_column($cursorHost->snapshots, 'afterId'), true), 'The stale continuation cursor must not create a duplicate or skipped page.');

    $languageRun = run($changePage, ['applied'], PHP_INT_MAX, 'fr-FR');
    $languageTmdb = $languageRun[3];
    $languageProperty = new \ReflectionProperty($languageTmdb, 'language'); $languageProperty->setAccessible(true);
    same('fr-FR', $languageProperty->getValue($languageTmdb), 'The plugin language override must be applied during the host-backed doEnrich flow.');

    $reflection = new \ReflectionClass(Plugin::class);
    foreach (['processDateFile', 'processSqliteStore', 'invalidatePlaylistEpgCaches'] as $adapter) { same(false, $reflection->hasMethod($adapter), "Direct adapter $adapter must not return."); }
    echo "Date-file heartbeat tests passed.\n";
}
