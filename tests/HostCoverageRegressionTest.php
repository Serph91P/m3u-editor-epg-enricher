<?php
if (! isset($argv[1])) {
    foreach (['stats', 'replay-stats', 'retry-stats', 'rebuild-first', 'rebuild-later'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case), $status);
        if ($status !== 0) { exit($status); }
    }
    exit(0);
}
// Offline production host path with the existing in-memory services.
require __DIR__.'/ProviderSeriesBindingHostTest.php';

use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\LegacyEpgCacheEnrichmentService;
use AppLocalPlugins\EpgEnricher\Plugin;

$case = $argv[1] ?? 'stats';
$context = new PluginExecutionContext();
$context->settings['overwrite_existing'] = false;
$context->settings['replace_existing_artwork'] = true;
if ($case === 'stats') {
    [$result, $host, $tmdb] = Tests\execute([Tests\row(1, ['title' => 'Unresolved Synthetic Work'])], context: $context);
    Tests\same('completed', $result->status, 'Conservative abstention does not fail the run.');
    Tests\same(true, count($tmdb->calls) > 0, 'An unmatched work must actually be looked up.');
    Tests\same(0, $result->data['programmes_already_enriched'], 'A missing requested poster is not complete.');
    Tests\same(1, $result->data['programmes_unmatched'] ?? null, 'Report unresolved identity separately.');
    Tests\same(1, $result->data['programmes_missing_artwork'] ?? null, 'Report missing requested artwork separately.');
    Tests\same(false, isset($host->records[1]['programme']['icon']), 'Abstention must not invent artwork.');
    Illuminate\Support\Facades\Http::$posterOverrides[301] = ['file_path'=>'/square.jpg','width'=>1000,'height'=>1000];
    [$matched] = Tests\execute([Tests\row(1,['title'=>'Direct Show'])], context:$context);
    Tests\same(0,$matched->data['programmes_unmatched'],'A safe identity with no eligible poster is not an identity miss.');
    Tests\same(1,$matched->data['programmes_missing_artwork'],'A matched work without qualified imagery remains artwork-incomplete.');
    Tests\same(0,$matched->data['programmes_already_enriched'],'Matched identity alone cannot claim requested artwork is complete.');
    Illuminate\Support\Facades\Http::$posterOverrides=[];
    $preserve=new PluginExecutionContext();
    $preserve->settings['overwrite_existing']=false;
    [$complete,,$tmdb]=Tests\execute([Tests\row(1,['title'=>'Already Complete','icon'=>'https://provider.invalid/poster.jpg','images'=>[['url'=>'https://provider.invalid/poster.jpg','type'=>'poster','orient'=>'P','width'=>600,'height'=>900,'size'=>2]]])],context:$preserve);
    Tests\same(1,$complete->data['programmes_already_enriched'],'A genuinely complete requested role remains already-enriched.');
    Tests\same(0,$complete->data['programmes_missing_artwork'],'A qualified provider poster satisfies the requested role.');
    Tests\same([],$tmdb->calls,'A complete preserve record still takes the legitimate no-lookup shortcut.');
} elseif ($case === 'retry-stats') {
    Illuminate\Support\Facades\Storage::$files=[];
    $host=new class([Tests\row(1,['title'=>'Direct Show'])]) extends LegacyEpgCacheEnrichmentService {
        public int $attempts=0;
        public function apply(object $context,object $epg,array $patches): array {
            if (++$this->attempts===1) {
                $this->records=[1=>Tests\row(1,['title'=>'Unresolved Replacement Work'])];
                return ['status'=>'stale'];
            }
            return parent::apply($context,$epg,$patches);
        }
    };
    $GLOBALS['bindingHostServices'][EpgCacheEnrichmentService::class]=$host;
    $progress=null;
    $result=(new ReflectionMethod(Plugin::class,'enrichHostSnapshotPages'))->invokeArgs(new Plugin(),[new App\Models\Epg(1),1,[1],$context,&$progress]);
    Tests\same('completed',$result->status,'A bounded first-page replacement with abstention can complete.');
    Tests\same(1,$result->data['programmes_processed'],'Only the accepted first-page snapshot counts as coverage.');
    Tests\same(1,$result->data['programmes_unmatched'],'Retry statistics must describe the replacement row, not the discarded match.');
    Tests\same(1,$result->data['programmes_missing_artwork'],'The replacement row still lacks requested artwork.');
} elseif ($case === 'replay-stats') {
    Illuminate\Support\Facades\Storage::$files=[];
    $host=new LegacyEpgCacheEnrichmentService([Tests\row(1,['title'=>'Direct Show']),Tests\row(2,['title'=>'Direct Show'])]);
    $tmdb=new class extends App\Services\TmdbService {
        private bool $miss=true;
        public function searchTvSeriesCandidates(string $name, ?int $year=null, int $limit=5): array {
            if ($this->miss) { $this->miss=false; return []; }
            return parent::searchTvSeriesCandidates($name,$year,$limit);
        }
    };
    $GLOBALS['bindingHostServices'][EpgCacheEnrichmentService::class]=$host;
    $GLOBALS['bindingHostServices'][App\Services\TmdbService::class]=$tmdb;
    $progress=null;
    $result=(new ReflectionMethod(Plugin::class,'enrichHostSnapshotPages'))->invokeArgs(new Plugin(),[new App\Models\Epg(1),1,[1],$context,&$progress]);
    Tests\same(2,$result->data['programmes_updated'],'A later cache hit repairs the first occurrence.');
    Tests\same(0,$result->data['programmes_unmatched'],'Resolved replay must not remain counted as unmatched.');
    Tests\same(0,$result->data['programmes_missing_artwork'],'Resolved replay must not remain counted as missing requested artwork.');
} elseif (in_array($case, ['rebuild-first', 'rebuild-later'], true)) {
    $host = new class([Tests\row(1, ['title' => 'Direct Show']), Tests\row(2, ['title' => 'Direct Show']), Tests\row(3, ['title' => 'Direct Show'])]) extends LegacyEpgCacheEnrichmentService {
        public int $attempts = 0;
        public int $rebuildAt = 2;
        public function apply(object $context, object $epg, array $patches): array {
            if (++$this->attempts === $this->rebuildAt) {
                foreach ($this->records as &$row) {
                    unset($row['programme']['images'], $row['programme']['icon']);
                    $row['hash'] = 'new-store-'.$row['id'];
                }
                return ['status' => 'stale'];
            }
            return parent::apply($context, $epg, $patches);
        }
    };
    $host->rebuildAt = $case === 'rebuild-first' ? 1 : 3;
    $GLOBALS['bindingHostServices'][EpgCacheEnrichmentService::class] = $host;
    $progress = null;
    $result = (new ReflectionMethod(Plugin::class, 'enrichHostSnapshotPages'))->invokeArgs(new Plugin(), [new App\Models\Epg(1), 1, [1], $context, &$progress]);
    if ($case === 'rebuild-later') {
        Tests\same('failed', $result->status, 'A rebuilt store after earlier accepted pages must not report completed with lost coverage.');
        Tests\same(3, $host->attempts, 'A later store change must stop without an unbounded retry.');
    } else {
        Tests\same('completed', $result->status, 'The bounded first-page retry covers the whole replacement store.');
        Tests\same(3, $result->data['programmes_processed'], 'First-page retry must count accepted rows once.');
        foreach ($host->records as $row) { Tests\same(true, !empty($row['programme']['icon']), 'Every replacement row is visited.'); }
    }
    // A normal subsequent source/hook invocation starts at zero and can finish.
    $result = (new ReflectionMethod(Plugin::class, 'enrichHostSnapshotPages'))->invokeArgs(new Plugin(), [new App\Models\Epg(1), 1, [1], $context, &$progress]);
    Tests\same('completed', $result->status, 'A new normal run can recover a failed stale run.');
    foreach ($host->records as $row) { Tests\same(true, !empty($row['programme']['icon']), 'The resumed run covers every rebuilt row.'); }
} else {
    throw new RuntimeException('Unknown regression case: '.$case);
}
echo "Host coverage regression {$case} passed.\n";
