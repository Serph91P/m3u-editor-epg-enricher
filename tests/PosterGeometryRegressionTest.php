<?php
if (! isset($argv[1])) {
    foreach (['fallback', 'boundaries', 'unknown-fresh', 'aspect-conflict'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case), $status);
        if ($status !== 0) { exit($status); }
    }
    exit(0);
}
putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';
use App\Services\CandidateTmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\FakeHttpResponse;

function geometrySame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) { throw new RuntimeException($message.' expected '.json_encode($expected).' got '.json_encode($actual)); }
}
$GLOBALS['tmdbTestSettings'] = new GeneralSettings();
$GLOBALS['tmdbTestSettings']->tmdb_api_key = 'synthetic-offline';
$plugin = new Plugin();
$enrich = new ReflectionMethod($plugin, 'enrichProgrammeFromTmdb');
$canonical = new ReflectionMethod($plugin, 'canonicalHostChanges');
$case = $argv[1] ?? 'fallback';
$url = 'https://image.tmdb.org/t/p/w500/synthetic-square.jpg';
if ($case === 'fallback') {
    foreach ([false, true] as $overwrite) {
        foreach (['lookup', 'cache', 'replay'] as $path) {
            $details = Tests\normalizedMovieDetailsFixture(98001, 'Synthetic Fixture', null, $url, null);
            $service = new CandidateTmdbService(movieCandidates: [['tmdb_id'=>98001, 'title'=>'Synthetic Fixture', 'original_title'=>'Synthetic Fixture', 'release_date'=>'2020-01-01', 'overview'=>'Synthetic overview.']], movieDetails: [98001=>$details]);
            $bad = ['posters'=>[['file_path'=>'/synthetic-square.jpg','width'=>1000,'height'=>1000,'iso_639_1'=>null]],'backdrops'=>[],'logos'=>[]];
            Http::$responses = [new FakeHttpResponse(true,$bad),new FakeHttpResponse(true,$bad)];
            $cache=[]; $images=[]; $seasons=[];
            $source=['title'=>'Synthetic Fixture','desc'=>'Keep description','category'=>'Movie'];
            $programme=$source;
            Tests\enrich($plugin,$enrich,$programme,$service,$cache,imagesCache:$images,seasonCache:$seasons,enrichBackdrops:false,overwriteArtwork:$overwrite);
            if ($path !== 'lookup') {
                $programme=$source;
                Tests\enrich($plugin,$enrich,$programme,$service,$cache,imagesCache:$images,seasonCache:$seasons,enrichBackdrops:false,overwriteArtwork:$overwrite,allowProviderLookup:$path !== 'replay');
            }
            $patch=$canonical->invoke($plugin,$source,$programme,$overwrite);
            $published=array_replace($source,$patch);
            geometrySame(false, isset($published['icon']), 'Rejected square cannot become Primary in '.$path);
            geometrySame([], $published['images']??[], 'Rejected square cannot be relabelled as a portrait.');
            geometrySame($source['desc'],$published['desc'],'Artwork-only preserves text.');
            geometrySame($source['category'],$published['category'],'Artwork-only preserves category.');
        }
    }
} elseif ($case === 'boundaries') {
    $invalid=['url'=>$url,'type'=>'poster','orient'=>'P','width'=>1000,'height'=>1000,'size'=>2,'source'=>'tmdb'];
    $programme=['images'=>[$invalid]];
    (new ReflectionMethod($plugin,'finalizeImageSerialization'))->invokeArgs($plugin,[&$programme,false,true]);
    geometrySame(false,isset($programme['icon']),'Finalization cannot select a contradictory poster.');
    geometrySame(null,(new ReflectionMethod($plugin,'canonicalHostImage'))->invoke($plugin,$invalid),'Canonicalization rejects contradictory new poster metadata.');
    $legacy=['title'=>'Synthetic Fixture','icon'=>'https://provider.invalid/legacy.jpg','images'=>[['url'=>'https://provider.invalid/legacy.jpg','type'=>'poster','orient'=>'P','width'=>0,'height'=>0,'size'=>2]]];
    geometrySame([], $canonical->invoke($plugin,$legacy,$legacy,false),'Unknown historical geometry is preserved unchanged.');
} elseif ($case === 'unknown-fresh') {
    $selected = (new ReflectionMethod($plugin,'selectImageSet'))->invoke($plugin,['posters'=>[['file_path'=>'/unknown-geometry.jpg']],'backdrops'=>[],'logos'=>[]],'de-DE');
    geometrySame([], $selected, 'Fresh metadata without geometry must not become an invented portrait.');
} elseif ($case === 'aspect-conflict') {
    $conflict=['url'=>$url,'type'=>'poster','orient'=>'P','width'=>500,'height'=>750,'aspect_ratio'=>1.0,'size'=>2];
    geometrySame(false,(new ReflectionMethod($plugin,'isTrustedPortraitImage'))->invoke($plugin,$conflict),'Known contradictory aspect metadata cannot be trusted.');
    $programme=['images'=>[$conflict]];
    (new ReflectionMethod($plugin,'finalizeImageSerialization'))->invokeArgs($plugin,[&$programme,false,true]);
    geometrySame(false,isset($programme['icon']),'Finalization must respect explicit aspect conflicts.');
} else { throw new RuntimeException('Unknown case'); }
echo "Poster geometry {$case} passed.\n";
