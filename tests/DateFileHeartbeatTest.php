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

    $times = [0.0, 59.0, 60.0, 119.0, 120.0, 121.0];
    $context = new PluginExecutionContext();
    runDateFile($plugin, $method, 'long.jsonl', ['target'], $context, function () use (&$times): float {
        return array_shift($times);
    });

    assertSameValue(2, count($context->heartbeats), 'Long date files should refresh the heartbeat at a throttled interval.');
    assertSameValue(
        [
            'Processing 2026-07-11 (2/4) - 1 programmes processed',
            'Processing 2026-07-11 (2/4) - 3 programmes processed',
        ],
        array_column($context->heartbeats, 'message'),
        'Intra-day heartbeat messages should report monotonic processed counts.'
    );
    assertSameValue([35, 45], array_column($context->heartbeats, 'progress'), 'Intra-day progress should advance within the current day.');

    $untargetedRecords = '';
    foreach (range(1, 3) as $index) {
        $untargetedRecords .= json_encode([
            'channel' => 'other',
            'programme' => ['title' => 'Untargeted'],
        ], JSON_UNESCAPED_SLASHES)."\n";
    }
    file_put_contents($tempDir.'/untargeted.jsonl', $untargetedRecords);

    $untargetedTimes = [0.0, 59.0, 60.0, 61.0];
    $untargetedContext = new PluginExecutionContext();
    runDateFile($plugin, $method, 'untargeted.jsonl', ['target'], $untargetedContext, function () use (&$untargetedTimes): float {
        return array_shift($untargetedTimes);
    });
    assertSameValue(
        1,
        count($untargetedContext->heartbeats),
        'Long scans should refresh the heartbeat even when the current records are not targeted.'
    );
    assertSameValue(
        'Processing 2026-07-11 (2/4) - 0 programmes processed',
        $untargetedContext->heartbeats[0]['message'] ?? null,
        'Untargeted scan heartbeats should keep the processed programme count truthful.'
    );

    $largeChannelIds = [];
    $largeRecords = '';
    foreach (range(1, 700) as $index) {
        $channelId = "target-{$index}";
        $largeChannelIds[] = $channelId;
        $largeRecords .= json_encode([
            'channel' => $channelId,
            'programme' => ['title' => ''],
        ], JSON_UNESCAPED_SLASHES)."\n";
    }
    file_put_contents($tempDir.'/large-channel-set.jsonl', $largeRecords);

    $largeTime = -1.0;
    $largeContext = new PluginExecutionContext();
    $largeResult = runDateFile(
        $plugin,
        $method,
        'large-channel-set.jsonl',
        $largeChannelIds,
        $largeContext,
        function () use (&$largeTime): float {
            return ++$largeTime;
        },
    );
    assertSameValue(700, $largeResult['processed'], 'A large run should process all 700 targeted channels.');
    assertSameValue(11, count($largeContext->heartbeats), 'A 700-second run should refresh its heartbeat every 60 seconds.');
    assertSameValue(
        'Processing 2026-07-11 (2/4) - 659 programmes processed',
        $largeContext->heartbeats[10]['message'] ?? null,
        'Heartbeat progress should remain monotonic through a 700-channel run.'
    );

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
    $cancelTimes = [0.0, 1.0, 2.0];
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

    $persistenceFailureFile = json_encode([
        'channel' => 'target',
        'programme' => ['title' => 'Wimbledon'],
    ], JSON_UNESCAPED_SLASHES)."\n";
    file_put_contents($tempDir.'/persistence-failure.jsonl', $persistenceFailureFile);
    mkdir($tempDir.'/persistence-failure.jsonl.enriching');
    $persistenceFailureContext = new PluginExecutionContext();
    $persistenceFailureTimes = [0.0, 1.0];
    set_error_handler(static fn (): bool => true);
    try {
        $persistenceFailureResult = runDateFile(
            $plugin,
            $method,
            'persistence-failure.jsonl',
            ['target'],
            $persistenceFailureContext,
            function () use (&$persistenceFailureTimes): float {
                return array_shift($persistenceFailureTimes);
            },
            true,
        );
    } finally {
        restore_error_handler();
    }
    assertSameValue(false, $persistenceFailureResult['modified'], 'A failed temporary write must not report a modified date file.');
    assertSameValue(0, $persistenceFailureResult['updated'], 'A failed temporary write must not count an unpersisted programme update.');
    assertSameValue($persistenceFailureFile, file_get_contents($tempDir.'/persistence-failure.jsonl'), 'A failed temporary write must preserve the original date file.');

    unlink($tempDir.'/long.jsonl');
    unlink($tempDir.'/untargeted.jsonl');
    unlink($tempDir.'/large-channel-set.jsonl');
    unlink($tempDir.'/cancel.jsonl');
    unlink($tempDir.'/small.jsonl');
    unlink($tempDir.'/persistence-failure.jsonl');
    rmdir($tempDir.'/persistence-failure.jsonl.enriching');
    rmdir($tempDir);

    echo "Date-file heartbeat tests passed.\n";
}
