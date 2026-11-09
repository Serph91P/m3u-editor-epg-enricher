<?php
require __DIR__.'/ProviderSeriesBindingHostTest.php';

use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\LegacyEpgCacheEnrichmentService;
use AppLocalPlugins\EpgEnricher\Plugin;

$url = 'https://provider.invalid/still.jpg';
$host = new class([Tests\row(1, ['title' => 'Unresolved Synthetic Work', 'icon' => $url, 'images' => [['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 3]]])]) extends LegacyEpgCacheEnrichmentService {
    public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array
    {
        return parent::snapshot($context, $epg, $afterId, $limit) + ['supported_fields' => ['preserved_primary_url']];
    }
};
$GLOBALS['bindingHostServices'][EpgCacheEnrichmentService::class] = $host;
$context = new PluginExecutionContext();
$context->settings['overwrite_existing'] = false;
$method = new ReflectionMethod(Plugin::class, 'enrichHostSnapshotPages');
$run = function () use ($method, $context): object {
    $progress = null;
    return $method->invokeArgs(new Plugin(), [new App\Models\Epg(1), 1, [1], $context, &$progress]);
};
Tests\same('completed', $run()->status, 'Legacy host completes without plugin table.');
Tests\same($url, $host->records[1]['programme']['icon'], 'Lookup abstention preserves the normal scalar.');
Tests\same(false, isset($host->records[1]['programme']['preserved_primary_url']), 'Legacy advertised field is not written.');
$applies = count($host->applies);
Tests\same('completed', $run()->status, 'Idempotent Preserve completes.');
Tests\same($applies, count($host->applies), 'No marker-only host apply.');
$context->settings['replace_existing_artwork'] = true;
Tests\same('completed', $run()->status, 'Effective artwork overwrite can be enabled independently.');
Tests\same(false, isset($host->records[1]['programme']['preserved_primary_url']), 'No host marker even on overwrite noop.');
// Canonical readback, ledger, stale retry and rebuild are exercised in tests/native.
echo "Preserved Primary legacy host paths passed.\n";
