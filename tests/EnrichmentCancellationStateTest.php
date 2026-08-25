<?php

namespace {
    function app(string $class): object
    {
        return $class === App\Services\EpgCacheService::class
            ? new App\Services\EpgCacheService()
            : new App\Services\TmdbService();
    }

    function now(): object
    {
        return new class
        {
            public function toIso8601String(): string
            {
                return '2026-07-11T12:00:00+00:00';
            }
        };
    }

    function storage_path(string $path = ''): string
    {
        return Illuminate\Support\Facades\Storage::$root.($path === '' ? '' : '/'.$path);
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
        public function __construct(
            public readonly string $status,
            public readonly bool $success,
            public readonly string $summary,
            public readonly array $data = [],
        ) {}

        public static function success(string $summary, array $data = []): self
        {
            return new self('completed', true, $summary, $data);
        }

        public static function failure(string $summary, array $data = []): self
        {
            return new self('failed', false, $summary, $data);
        }

        public static function cancelled(string $summary, array $data = []): self
        {
            return new self('cancelled', false, $summary, $data);
        }
    }

    class PluginExecutionContext
    {
        public array $settings = [
            'enrich_from_tmdb' => true,
            'overwrite_existing' => false,
            'enrich_categories' => true,
            'enrich_descriptions' => false,
            'enrich_posters' => false,
            'enrich_backdrops' => false,
            'map_genres_to_epg_categories' => true,
            'map_genres_to_kodi_guide_genres' => false,
            'keyword_category_detection' => true,
            'enrich_episode_details' => false,
        ];
        public int $cancellationChecks = 0;
        public int $cancelAfterChecks = PHP_INT_MAX;
        public array $messages = [];

        public function cancellationRequested(): bool
        {
            $this->cancellationChecks++;

            return $this->cancellationChecks >= $this->cancelAfterChecks;
        }

        public function heartbeat(string $message, ?int $progress = null): void
        {
            $this->messages[] = $message;
        }

        public function info(string $message): void
        {
            $this->messages[] = $message;
        }

        public function warning(string $message): void
        {
            $this->messages[] = $message;
        }
    }
}

namespace App\Models {
    class FakeCollection
    {
        public function __construct(private array $values) {}

        public function filter(): self
        {
            return new self(array_values(array_filter($this->values)));
        }

        public function unique(): self
        {
            return new self(array_values(array_unique($this->values)));
        }

        public function values(): self
        {
            return $this;
        }

        public function all(): array
        {
            return $this->values;
        }
    }

    class FakeQuery
    {
        public function __construct(private string $model) {}

        public function __call(string $name, array $arguments): self
        {
            return $this;
        }

        public function pluck(string $column): FakeCollection
        {
            return new FakeCollection($this->model === Channel::class ? [1] : ['target']);
        }
    }

    class Channel
    {
        public static function query(): FakeQuery
        {
            return new FakeQuery(self::class);
        }
    }

    class EpgChannel
    {
        public static function query(): FakeQuery
        {
            return new FakeQuery(self::class);
        }
    }

    class Epg
    {
        public string $name = 'Cancellation fixture';
        public string $uuid = 'cancel-fixture';

        public static function find(int $id): self
        {
            return new self();
        }
    }

    class Playlist {}
}

namespace App\Services {
    class EpgCacheService
    {
        public function isCacheValid(object $epg): bool
        {
            return true;
        }
    }

    class TmdbService
    {
        public function isConfigured(): bool
        {
            return true;
        }
    }
}

namespace App\Settings {
    class GeneralSettings {}
}

namespace Carbon {
    class Carbon
    {
        private function __construct(private \DateTimeImmutable $date) {}

        public static function parse(string $date): self
        {
            return new self(new \DateTimeImmutable($date));
        }

        public function diffInDays(self $other): int
        {
            return (int) $this->date->diff($other->date)->days;
        }

        public function lte(self $other): bool
        {
            return $this->date <= $other->date;
        }

        public function format(string $format): string
        {
            return $this->date->format($format);
        }

        public function addDay(): self
        {
            $this->date = $this->date->modify('+1 day');

            return $this;
        }
    }
}

namespace Illuminate\Support\Facades {
    use RuntimeException;

    class Storage
    {
        public static string $root;
        public static ?string $throwOnExistsPath = null;
        public static ?string $failPutPath = null;

        public static function disk(string $name): self
        {
            return new self();
        }

        public function path(string $path): string
        {
            return self::$root.'/'.$path;
        }

        public function exists(string $path): bool
        {
            if ($path === self::$throwOnExistsPath) {
                throw new RuntimeException('Simulated worker termination before the next date file.');
            }

            return file_exists($this->path($path));
        }

        public function get(string $path): string
        {
            return (string) file_get_contents($this->path($path));
        }

        public function put(string $path, string $contents): bool
        {
            if ($path === self::$failPutPath) {
                return false;
            }

            return file_put_contents($this->path($path), $contents) !== false;
        }

        public function delete(string $path): void
        {
            @unlink($this->path($path));
        }

        public function makeDirectory(string $path): void
        {
            if (! is_dir($this->path($path))) {
                mkdir($this->path($path), 0777, true);
            }
        }
    }

    class Http {}
    class Log {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginExecutionContext;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Storage;
    use ReflectionMethod;
    use RuntimeException;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    $tempDir = sys_get_temp_dir().'/epg-enricher-cancel-state-'.bin2hex(random_bytes(6));
    $cacheDir = $tempDir.'/epg-cache/cancel-fixture/v2';
    $stateDir = $tempDir.'/plugin-data/epg-enricher';
    mkdir($cacheDir, 0777, true);
    mkdir($stateDir, 0777, true);
    Storage::$root = $tempDir;

    file_put_contents($cacheDir.'/metadata.json', json_encode([
        'programme_date_range' => [
            'min_date' => '2026-07-11',
            'max_date' => '2026-07-11',
        ],
    ]));
    $source = json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n".json_encode([
        'channel' => 'other',
        'programme' => ['title' => 'Untargeted'],
    ], JSON_UNESCAPED_SLASHES)."\n";
    $dateFile = $cacheDir.'/programmes-2026-07-11.jsonl';
    file_put_contents($dateFile, $source);

    $legacySettingsHash = md5(json_encode([
        'logic_version' => '2026.08.21-v1.13.8-icon-order',
        'enrich_from_tmdb' => true,
        'overwrite_existing' => false,
        'enrich_categories' => true,
        'enrich_descriptions' => false,
        'enrich_posters' => false,
        'enrich_backdrops' => false,
        'map_genres_to_epg_categories' => true,
        'map_genres_to_kodi_guide_genres' => false,
        'keyword_category_detection' => true,
        'enrich_episode_details' => false,
        'tmdb_language' => '',
    ]));

    $priorState = [
        'source_hash' => 'prior-source',
        'enriched_hash' => 'prior-enriched',
        'enriched_at' => '2026-07-10T12:00:00+00:00',
        'programmes_updated' => 1,
    ];
    file_put_contents($stateDir.'/enrichment-state.json', json_encode([
        'epg_1' => [
            'settings_hash' => $legacySettingsHash,
            'channels_hash' => md5(json_encode(['target'])),
            'files' => [
                'programmes-2026-07-10.jsonl' => $priorState,
                'programmes-2026-07-11.jsonl' => [
                    'source_hash' => md5_file($dateFile),
                    'enriched_hash' => md5_file($dateFile),
                    'enriched_at' => '2026-07-10T12:00:00+00:00',
                    'programmes_updated' => 0,
                ],
            ],
        ],
    ]));

    $plugin = new Plugin();
    $method = new ReflectionMethod($plugin, 'doEnrich');
    $method->setAccessible(true);

    $settingsHashMethod = new ReflectionMethod($plugin, 'computeSettingsHash');
    $settingsHashMethod->setAccessible(true);
    assertSameValue(
        true,
        $legacySettingsHash !== $settingsHashMethod->invoke($plugin, (new PluginExecutionContext())->settings),
        'The title-card quality logic version must invalidate a previously processed state hash.'
    );

    $cancelContext = new PluginExecutionContext();
    $cancelContext->cancelAfterChecks = 3;
    $cancelResult = $method->invoke($plugin, 1, [1], $cancelContext);

    $stateAfterCancellation = json_decode(file_get_contents($stateDir.'/enrichment-state.json'), true);
    assertSameValue($source, file_get_contents($dateFile), 'Cancellation should leave source JSONL bytes unchanged.');
    assertSameValue(
        false,
        isset($stateAfterCancellation['epg_1']['files']['programmes-2026-07-11.jsonl']),
        'A cancelled date file must not be recorded as complete.'
    );
    assertSameValue(
        false,
        isset($stateAfterCancellation['epg_1']['files']['programmes-2026-07-10.jsonl']),
        'A changed logic version should invalidate all previously completed file states.'
    );
    assertSameValue('cancelled', $cancelResult->status, 'Cancellation inside a date file should propagate to doEnrich.');
    assertSameValue(1, $cancelResult->data['programmes_updated'] ?? null, 'The fixture should modify a programme in memory before cancellation.');

    $retryContext = new PluginExecutionContext();
    $retryResult = $method->invoke($plugin, 1, [1], $retryContext);
    assertSameValue(1, $retryResult->data['programmes_updated'] ?? null, 'A subsequent run should process the cancelled date file.');
    assertSameValue(false, $source === file_get_contents($dateFile), 'The subsequent run should write the enrichment.');

    $checkpointTempDir = sys_get_temp_dir().'/epg-enricher-day-checkpoint-'.bin2hex(random_bytes(6));
    $checkpointCacheDir = $checkpointTempDir.'/epg-cache/cancel-fixture/v2';
    $checkpointStateDir = $checkpointTempDir.'/plugin-data/epg-enricher';
    mkdir($checkpointCacheDir, 0777, true);
    mkdir($checkpointStateDir, 0777, true);
    Storage::$root = $checkpointTempDir;

    file_put_contents($checkpointCacheDir.'/metadata.json', json_encode([
        'programme_date_range' => [
            'min_date' => '2026-07-11',
            'max_date' => '2026-07-12',
        ],
    ]));
    file_put_contents($checkpointCacheDir.'/programmes-2026-07-11.jsonl', json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($checkpointCacheDir.'/programmes-2026-07-12.jsonl', json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n");

    Storage::$throwOnExistsPath = 'epg-cache/cancel-fixture/v2/programmes-2026-07-12.jsonl';

    try {
        $method->invoke($plugin, 1, [1], new PluginExecutionContext());
        fwrite(STDERR, "Expected simulated worker termination.\n");
        exit(1);
    } catch (RuntimeException $exception) {
        assertSameValue(
            'Simulated worker termination before the next date file.',
            $exception->getMessage(),
            'The fixture should stop immediately before the second date file.'
        );
    }

    $checkpointPath = $checkpointStateDir.'/enrichment-checkpoint-epg_1.json';
    $checkpoint = json_decode((string) file_get_contents($checkpointPath), true);
    assertSameValue(
        true,
        isset($checkpoint['epg_1']['files']['programmes-2026-07-11.jsonl']),
        'A completed date file should be checkpointed before the next date starts.'
    );
    assertSameValue(
        false,
        isset($checkpoint['epg_1']['files']['programmes-2026-07-12.jsonl']),
        'An unstarted date file must not be checkpointed.'
    );
    assertSameValue(
        false,
        file_exists($checkpointStateDir.'/enrichment-state.json'),
        'A hard-stop checkpoint must not replace the canonical cross-EPG state file.'
    );

    Storage::$throwOnExistsPath = null;
    $checkpointRetry = $method->invoke($plugin, 1, [1], new PluginExecutionContext());
    assertSameValue(1, $checkpointRetry->data['days_skipped'] ?? null, 'A retry should skip the checkpointed first date file.');
    assertSameValue(1, $checkpointRetry->data['programmes_processed'] ?? null, 'A retry should process only the remaining date file.');
    assertSameValue(false, file_exists($checkpointPath), 'A successful retry should remove its per-EPG checkpoint.');

    file_put_contents($checkpointPath, '{}');
    $clearMethod = new ReflectionMethod($plugin, 'clearEnrichmentState');
    $clearMethod->setAccessible(true);
    $clearMethod->invoke($plugin, new PluginExecutionContext());
    assertSameValue(false, file_exists($checkpointPath), 'Clearing enrichment state should remove stale per-EPG checkpoints.');

    $saveEpgStateMethod = new ReflectionMethod($plugin, 'saveEpgEnrichmentState');
    $saveEpgStateMethod->setAccessible(true);
    $saveEpgStateMethod->invoke($plugin, 'epg_1', ['files' => ['first.jsonl' => ['source_hash' => 'first']]]);
    $saveEpgStateMethod->invoke($plugin, 'epg_2', ['files' => ['second.jsonl' => ['source_hash' => 'second']]]);
    $mergedState = json_decode((string) file_get_contents($checkpointStateDir.'/enrichment-state.json'), true);
    assertSameValue(
        ['epg_1', 'epg_2'],
        array_keys($mergedState),
        'Canonical state writes should merge independently completed EPG sources.'
    );

    @unlink($checkpointStateDir.'/enrichment-state.json');
    file_put_contents($checkpointCacheDir.'/metadata.json', json_encode([
        'programme_date_range' => [
            'min_date' => '2026-07-11',
            'max_date' => '2026-07-11',
        ],
    ]));
    file_put_contents($checkpointCacheDir.'/programmes-2026-07-11.jsonl', json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n");
    Storage::$failPutPath = 'plugin-data/epg-enricher/enrichment-state.json';
    try {
        $method->invoke($plugin, 1, [1], new PluginExecutionContext());
        fwrite(STDERR, "Expected canonical state write failure.\n");
        exit(1);
    } catch (RuntimeException $exception) {
        assertSameValue(
            'Could not persist enrichment state.',
            $exception->getMessage(),
            'A failed canonical state write should abort completion.'
        );
    }
    assertSameValue(true, file_exists($checkpointPath), 'A failed canonical state write must preserve the per-EPG checkpoint.');
    Storage::$failPutPath = null;

    $priorCheckpoint = '{"prior":true}';
    file_put_contents($checkpointPath, $priorCheckpoint);
    Storage::$failPutPath = 'plugin-data/epg-enricher/enrichment-checkpoint-epg_1.json';
    $saveCheckpointMethod = new ReflectionMethod($plugin, 'saveEnrichmentCheckpoint');
    $saveCheckpointMethod->setAccessible(true);
    try {
        $saveCheckpointMethod->invoke($plugin, 'epg_1', ['files' => []]);
        fwrite(STDERR, "Expected checkpoint write failure.\n");
        exit(1);
    } catch (RuntimeException $exception) {
        assertSameValue(
            'Could not persist enrichment checkpoint.',
            $exception->getMessage(),
            'A failed checkpoint write should abort progress.'
        );
    }
    assertSameValue($priorCheckpoint, file_get_contents($checkpointPath), 'A failed checkpoint write must preserve the prior checkpoint bytes.');
    Storage::$failPutPath = null;

    foreach ([$tempDir, $checkpointTempDir] as $directory) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }

    echo "Enrichment cancellation state tests passed.\n";
}
