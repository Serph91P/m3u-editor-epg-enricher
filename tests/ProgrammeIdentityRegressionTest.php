<?php
if (! isset($argv[1])) {
    foreach (['story-year', 'series-episode', 'remake-cache'] as $case) {
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($case), $status);
        if ($status !== 0) { exit($status); }
    }
    exit(0);
}
putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';
use App\Services\CandidateTmdbService;
use AppLocalPlugins\EpgEnricher\Plugin;

$case=$argv[1]??'story-year';
$GLOBALS['tmdbTestSettings']=new App\Settings\GeneralSettings();
$plugin=new Plugin();
$method=new ReflectionMethod($plugin,'enrichProgrammeFromTmdb');
$title='Synthetic Winter Harbor';
$movie=['tmdb_id'=>98101,'title'=>$title,'original_title'=>$title,'release_date'=>'2022-01-01','overview'=>'Investigators uncover hidden letters beneath ancient bridges.'];
$desc='In 1945, investigators uncover hidden letters beneath ancient bridges.';
$service=new CandidateTmdbService(movieCandidates:[$movie],movieDetails:[98101=>Tests\normalizedMovieDetailsFixture(98101,$title,$movie['overview'])]);
$programme=['title'=>$title,'desc'=>$desc];
$cache=[];
if ($case === 'story-year') {
    $result=Tests\enrich($plugin,$method,$programme,$service,$cache);
    Tests\assertSameValue(true,$result['provider_match'],'A plot year is not release identity evidence.');
    Tests\assertSameValue([null],$service->movieYears,'Do not pass a plot year to the provider.');
    foreach ([['production_year'=>2022],['desc'=>'Produced in 2022.'],['desc'=>'USA 2022. Synthetic plot.'],['desc'=>'Thriller 2022 von Synthetic Director.']] as $evidence) {
        $programme=array_replace(['title'=>$title],$evidence);$cache=[];
        $before=count($service->movieYears);
        Tests\enrich($plugin,$method,$programme,$service,$cache);
        Tests\assertSameValue([2022],array_slice($service->movieYears,$before),'Clear production evidence still filters the provider.');
    }
} elseif ($case === 'series-episode') {
    $GLOBALS['tmdbTestSettings']->tmdb_api_key='synthetic-offline';
    $series=['tmdb_id'=>98201,'name'=>'Synthetic Coastline','original_name'=>'Synthetic Coastline','first_air_date'=>'2010-01-01','overview'=>'Detectives investigate ancient letters beneath coastal bridges.'];
    $service=new CandidateTmdbService(tvCandidates:[$series],tvDetails:[98201=>Tests\normalizedTvDetailsFixture(98201,$series['name'],$series['overview'])],seasonDetails:['98201:2'=>['episodes'=>[['episode_number'=>9,'name'=>'Another Chapter','overview'=>'Wrong episode text.','still_path'=>'/wrong-chapter.jpg']]]]);
    $cache=[];$seasons=[];
    $images=['tv:98201:de-de:'.(new ReflectionClass(Plugin::class))->getConstant('ENRICHMENT_LOGIC_VERSION')=>['posters'=>[['file_path'=>'/coastline.jpg','width'=>900,'height'=>1400]],'backdrops'=>[],'logos'=>[]]];
    $programme=['title'=>$series['name'],'subtitle'=>'Provider Chapter','desc'=>$series['overview'],'episode_nums'=>[['system'=>'xmltv_ns','value'=>'1.8.']]];
    $result=Tests\enrich($plugin,$method,$programme,$service,$cache,seasonCache:$seasons,imagesCache:$images,enrichEpisodeDetails:true,overwriteArtwork:true);
    Tests\assertSameValue(true,$result['provider_match'],'Independent series identity survives different provider episode numbering.');
    Tests\assertSameValue('https://image.tmdb.org/t/p/w500/coastline.jpg',$programme['icon']??null,'Confident common-work identity may supply the qualified series poster.');
    Tests\assertSameValue([],array_values(array_filter($programme['images']??[],fn($i)=>($i['scope']??null)==='episode')),'A conflicting episode title cannot release episode stills.');
    // A missing season does not invalidate an independently selected series.
    $seasons=['98201:2:de-de'=>null];
    $service=new CandidateTmdbService(tvCandidates:[$series],tvDetails:[98201=>Tests\normalizedTvDetailsFixture(98201,$series['name'],$series['overview'])]);
    $programme=['title'=>$series['name'],'subtitle'=>'Provider Chapter','episode_nums'=>[['system'=>'xmltv_ns','value'=>'1.8.']]];
    $cache=[];
    $result=Tests\enrich($plugin,$method,$programme,$service,$cache,seasonCache:$seasons,imagesCache:$images,enrichEpisodeDetails:true,overwriteArtwork:true);
    Tests\assertSameValue(true,$result['provider_match'],'A null season cache is not a negative series identity cache.');
    Tests\assertSameValue('https://image.tmdb.org/t/p/w500/coastline.jpg',$programme['icon']??null,'Missing season may not suppress independently qualified series artwork.');
    $service=new CandidateTmdbService(tvCandidates:[$series,array_replace($series,['tmdb_id'=>98202,'first_air_date'=>'2020-01-01'])],seasonResults:['98201:2'=>['status'=>'not_found'],'98202:2'=>['status'=>'not_found']]);
    $programme=['title'=>$series['name'],'subtitle'=>'Provider Chapter','episode_nums'=>[['system'=>'xmltv_ns','value'=>'1.8.']]];
    $cache=[];
    $result=Tests\enrich($plugin,$method,$programme,$service,$cache,seasonCache:$seasons,imagesCache:$images,enrichEpisodeDetails:true,overwriteArtwork:true);
    Tests\assertSameValue(false,$result['provider_match'],'Missing competing seasons and title equality alone cannot establish a common work.');
    Tests\assertSameValue(false,isset($programme['icon']),'An available portrait for one ambiguous candidate cannot authorize it.');
} elseif ($case === 'remake-cache') {
    $GLOBALS['tmdbTestSettings']->tmdb_api_key='synthetic-offline';
    $title='Synthetic Return';
    $candidates=[]; $details=[]; $images=[];
    foreach ([98301=>1982,98302=>2022] as $id=>$year) {
        $candidates[]=['tmdb_id'=>$id,'title'=>$title,'original_title'=>$title,'release_date'=>$year.'-01-01','overview'=>''];
        $details[$id]=Tests\normalizedMovieDetailsFixture($id,$title);
        $images['movie:'.$id.':de-de:'.(new ReflectionClass(Plugin::class))->getConstant('ENRICHMENT_LOGIC_VERSION')]=['posters'=>[['file_path'=>'/selected-'.$id.'.jpg','width'=>800,'height'=>1200]],'backdrops'=>[],'logos'=>[]];
    }
    $service=new CandidateTmdbService(movieCandidates:$candidates,movieDetails:$details);
    $cache=[];
    foreach ([[1982,98301],[2022,98302],[1982,98301]] as [$year,$id]) {
        $programme=['title'=>$title,'production_year'=>$year];
        Tests\enrich($plugin,$method,$programme,$service,$cache,imagesCache:$images,enrichBackdrops:false);
        Tests\assertSameValue('https://image.tmdb.org/t/p/w500/selected-'.$id.'.jpg',$programme['icon']??null,'Namesake cache entries must remain program-specific to the production evidence.');
    }
    $programme=['title'=>$title];
    $result=Tests\enrich($plugin,$method,$programme,$service,$cache,imagesCache:$images,enrichBackdrops:false);
    Tests\assertSameValue(false,$result['provider_match'],'Positive namesake cache entries cannot authorize an ambiguous programme.');
    Tests\assertSameValue(false,isset($programme['icon']),'Qualified image dimensions alone cannot prove namesake identity.');
} else { throw new RuntimeException('Unknown case'); }
echo "Programme identity {$case} passed.\n";
