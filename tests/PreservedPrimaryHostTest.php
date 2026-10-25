<?php
require __DIR__.'/ProviderSeriesBindingHostTest.php';

use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\LegacyEpgCacheEnrichmentService;
use AppLocalPlugins\EpgEnricher\Plugin;

$url = 'https://provider.invalid/still.jpg';
$host = new class([Tests\row(1, ['title' => 'Unresolved Synthetic Work', 'icon' => $url, 'images' => [['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 3]]])]) extends LegacyEpgCacheEnrichmentService {
    public bool $retry = false;
    public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array
    {
        return parent::snapshot($context, $epg, $afterId, $limit) + ['supported_fields' => ['preserved_primary_url']];
    }
    public function apply(object $context, object $epg, array $patches): array
    {
        if ($this->retry) {
            $this->retry = false;
            $this->records[1]['programme']['icon'] = 'https://provider.invalid/fresh.jpg';
            return ['status' => 'stale'];
        }
        return parent::apply($context, $epg, $patches);
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
Tests\same('completed', $run()->status, 'Supported host Preserve completes.');
Tests\same($url, $host->records[1]['programme']['preserved_primary_url'] ?? null, 'Real normal host path transports protection on lookup abstention.');
$applies = count($host->applies);
Tests\same('completed', $run()->status, 'Idempotent Preserve completes.');
Tests\same($applies, count($host->applies), 'Idempotent binding needs no additional apply.');
$context->settings['replace_existing_artwork'] = true;
Tests\same('completed', $run()->status, 'Effective artwork overwrite can be enabled independently.');
Tests\same('', $host->records[1]['programme']['preserved_primary_url'], 'Noop provider result still clears old protection.');
$context->settings['replace_existing_artwork'] = false;
$host->retry = true;
Tests\same('completed', $run()->status, 'First-page stale retry completes.');
Tests\same('https://provider.invalid/fresh.jpg', $host->records[1]['programme']['preserved_primary_url'], 'Retry recomputes protection from the fresh scalar, never the stale row.');
echo "Preserved Primary supported host paths passed.\n";
