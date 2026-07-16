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

    class PluginExecutionContext
    {
        public array $heartbeats = [];
        public int $cancellationChecks = 0;
        public int $cancelAfterChecks = PHP_INT_MAX;

        public function heartbeat(string $message, ?int $progress = null): void
        {
            $this->heartbeats[] = ['message' => $message, 'progress' => $progress];
        }

        public function cancellationRequested(): bool
        {
            $this->cancellationChecks++;

            return $this->cancellationChecks >= $this->cancelAfterChecks;
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
    }
}

namespace App\Services {
    class TmdbService {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\TmdbService;
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

    function runDateFile(
        Plugin $plugin,
        ReflectionMethod $method,
        string $file,
        array $targetChannels,
        PluginExecutionContext $context,
        callable $clock,
        bool $enableKeywordCategory = false,
    ): array {
        $tmdbCache = [];
        $seasonCache = [];
        $imagesCache = [];

        return $method->invokeArgs($plugin, [
            $file,
            $targetChannels,
            new TmdbService(),
            &$tmdbCache,
            false,
            $enableKeywordCategory,
            false,
            false,
            false,
            $enableKeywordCategory,
            false,
            $enableKeywordCategory,
            false,
            &$seasonCache,
            &$imagesCache,
            [
                'epg_source_id' => 'heartbeat-fixture',
                'tmdb_language' => 'en-US',
            ],
            $context,
            2,
            4,
            '2026-07-11',
            $clock,
        ]);
    }

    $tempDir = sys_get_temp_dir().'/epg-enricher-heartbeat-'.bin2hex(random_bytes(6));
    mkdir($tempDir);
    Storage::$root = $tempDir;

    $plugin = new Plugin();
    $method = new ReflectionMethod($plugin, 'processDateFile');
    $method->setAccessible(true);

    $targetRecords = '';
    foreach (range(1, 5) as $index) {
        $targetRecords .= json_encode([
            'channel' => 'target',
            'programme' => ['title' => ''],
        ], JSON_UNESCAPED_SLASHES)."\n";
    }
    file_put_contents($tempDir.'/long.jsonl', $targetRecords);

    $times = [0.0, 299.0, 300.0, 301.0, 599.0, 600.0];
    $context = new PluginExecutionContext();
    runDateFile($plugin, $method, 'long.jsonl', ['target'], $context, function () use (&$times): float {
        return array_shift($times);
    });

    assertSameValue(2, count($context->heartbeats), 'Long date files should refresh the heartbeat at a throttled interval.');
    assertSameValue(
        [
            'Processing 2026-07-11 (2/4) - 2 programmes processed',
            'Processing 2026-07-11 (2/4) - 5 programmes processed',
        ],
        array_column($context->heartbeats, 'message'),
        'Intra-day heartbeat messages should report monotonic processed counts.'
    );
    assertSameValue([35, 50], array_column($context->heartbeats, 'progress'), 'Intra-day progress should advance within the current day.');

    $cancelFile = json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n".str_repeat(json_encode([
        'channel' => 'other',
        'programme' => ['title' => 'Untargeted'],
    ], JSON_UNESCAPED_SLASHES)."\n", 20);
    file_put_contents($tempDir.'/cancel.jsonl', $cancelFile);
    $cancelContext = new PluginExecutionContext();
    $cancelContext->cancelAfterChecks = 3;
    $cancelTimes = [0.0, 1.0];
    $cancelResult = runDateFile($plugin, $method, 'cancel.jsonl', ['target'], $cancelContext, function () use (&$cancelTimes): float {
        return array_shift($cancelTimes);
    }, true);
    assertSameValue(3, $cancelContext->cancellationChecks, 'Cancellation should be checked for every record, independently of heartbeat timing.');
    assertSameValue(1, $cancelResult['updated'], 'The cancellation fixture should modify a programme before cancellation.');
    assertSameValue($cancelFile, file_get_contents($tempDir.'/cancel.jsonl'), 'Cancellation should leave the original date file byte-for-byte unchanged.');

    $smallFile = json_encode([
        'channel' => 'target',
        'programme' => ['title' => ''],
    ], JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($tempDir.'/small.jsonl', $smallFile);
    $smallContext = new PluginExecutionContext();
    $smallTimes = [0.0, 1.0];
    $smallResult = runDateFile($plugin, $method, 'small.jsonl', ['target'], $smallContext, function () use (&$smallTimes): float {
        return array_shift($smallTimes);
    });
    assertSameValue([], $smallContext->heartbeats, 'Small date files should not emit an unnecessary intra-day heartbeat.');
    assertSameValue($smallFile, file_get_contents($tempDir.'/small.jsonl'), 'Small-file output should remain unchanged.');
    assertSameValue(1, $smallResult['processed'], 'Small-file processing counts should remain unchanged.');

    unlink($tempDir.'/long.jsonl');
    unlink($tempDir.'/cancel.jsonl');
    unlink($tempDir.'/small.jsonl');
    rmdir($tempDir);

    echo "Date-file heartbeat tests passed.\n";
}
