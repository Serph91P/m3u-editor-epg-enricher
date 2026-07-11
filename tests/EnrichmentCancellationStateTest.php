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
}

namespace App\Plugins\Support {
    class PluginActionResult
    {
        public function __construct(public string $status, public array $data = []) {}

        public static function success(string $message, array $data = []): self
        {
            return new self('success', $data);
        }

        public static function failure(string $message, array $data = []): self
        {
            return new self('failure', $data);
        }

        public static function cancelled(string $message, array $data = []): self
        {
            return new self('cancelled', $data);
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
    class Storage
    {
        public static string $root;

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
            return file_exists($this->path($path));
        }

        public function get(string $path): string
        {
            return (string) file_get_contents($this->path($path));
        }

        public function put(string $path, string $contents): void
        {
            file_put_contents($this->path($path), $contents);
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

    $priorState = [
        'source_hash' => 'prior-source',
        'enriched_hash' => 'prior-enriched',
        'enriched_at' => '2026-07-10T12:00:00+00:00',
        'programmes_updated' => 1,
    ];
    file_put_contents($stateDir.'/enrichment-state.json', json_encode([
        'epg_1' => [
            'files' => ['programmes-2026-07-10.jsonl' => $priorState],
        ],
    ]));

    $plugin = new Plugin();
    $method = new ReflectionMethod($plugin, 'doEnrich');
    $method->setAccessible(true);

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
        $priorState,
        $stateAfterCancellation['epg_1']['files']['programmes-2026-07-10.jsonl'] ?? null,
        'Cancellation should preserve prior successfully completed file states.'
    );
    assertSameValue('cancelled', $cancelResult->status, 'Cancellation inside a date file should propagate to doEnrich.');
    assertSameValue(1, $cancelResult->data['programmes_updated'] ?? null, 'The fixture should modify a programme in memory before cancellation.');

    $retryContext = new PluginExecutionContext();
    $retryResult = $method->invoke($plugin, 1, [1], $retryContext);
    assertSameValue(1, $retryResult->data['programmes_updated'] ?? null, 'A subsequent run should process the cancelled date file.');
    assertSameValue(false, $source === file_get_contents($dateFile), 'The subsequent run should write the enrichment.');

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tempDir);

    echo "Enrichment cancellation state tests passed.\n";
}
