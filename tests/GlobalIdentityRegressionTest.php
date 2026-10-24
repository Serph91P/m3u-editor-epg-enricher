<?php
putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';

use App\Services\CandidateTmdbService;
use AppLocalPlugins\EpgEnricher\Plugin;

$GLOBALS['tmdbTestSettings'] = new App\Settings\GeneralSettings();
$plugin = new Plugin();
$enrich = new ReflectionMethod($plugin, 'enrichProgrammeFromTmdb');
$search = new ReflectionMethod($plugin, 'searchTmdbWithValidation');
$normalize = new ReflectionMethod($plugin, 'normalizeIdentityText');
$key = new ReflectionMethod($plugin, 'normalizeCacheKey');
$similarity = new ReflectionMethod($plugin, 'titleMatchScore');
$base = new ReflectionMethod($plugin, 'extractBaseTitle');
$failures = [];
function assertGlobal(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) { throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true)); }
}
function checkGlobal(string $name, callable $check): void {
    global $failures;
    try { $check(); echo "PASS {$name}\n"; }
    catch (Throwable $e) { $failures[] = $name; echo "FAIL {$name}: {$e->getMessage()}\n"; }
}
checkGlobal('unicode canonical casefold in identity similarity and cache', function () use ($plugin, $normalize, $key, $similarity, $enrich) {
    foreach ([['Straße im Winter', 'STRASSE IM WINTER'], ['ΟΣ Κόσμος', 'οσ κόσμος'], ["Cafe\u{0301} Überfahrt", 'CAFÉ ÜBERFAHRT'], ['Йога ДОМ', "И\u{0306}ОГА дом"], ["कि\u{0930}ण", "किरण"]] as [$a, $b]) {
        assertGlobal($normalize->invoke($plugin, $a), $normalize->invoke($plugin, $b), 'Canonical casefold.');
        assertGlobal($key->invoke($plugin, $a), $key->invoke($plugin, $b), 'Same cache normalization.');
        assertGlobal(1.0, $similarity->invoke($plugin, $a, $b), 'Exact folded title score.');
        $candidate = ['tmdb_id'=>991001, 'title'=>$b, 'original_title'=>$b, 'release_date'=>'2022-01-01', 'overview'=>''];
        $service = new CandidateTmdbService(movieCandidates:[$candidate], movieDetails:[991001=>Tests\normalizedMovieDetailsFixture(991001, $b)]);
        $programme = ['title'=>$a]; $cache=[];
        assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $service, $cache)['provider_match'], 'Real matcher must accept canonical identity.');
    }
    foreach ([['Café', 'Cafe'], ['東京 2', '東京 3'], ['किरण', 'करण'], ['Åland', 'Aland']] as [$a, $b]) {
        assertGlobal(false, $normalize->invoke($plugin, $a) === $normalize->invoke($plugin, $b), 'Do not erase accents, marks, numbers or scripts.');
        assertGlobal(false, $key->invoke($plugin, $a) === $key->invoke($plugin, $b), 'Distinct cache identities.');
    }
});
checkGlobal('year tokens in a work title survive', function () use ($plugin, $base) {
    foreach (['Synthetic 1984', '2001 Synthetic Odyssey', 'Synthetic 2020 Return'] as $title) {
        assertGlobal(['title'=>$title, 'year'=>null], $base->invoke($plugin, $title), 'Bare year is part of title.');
    }
    foreach (['Synthetic 1984 (2022)', 'Synthetic 1984 - 2022'] as $title) {
        assertGlobal(['title'=>'Synthetic 1984', 'year'=>2022], $base->invoke($plugin, $title), 'Only explicit trailing marker supplies year.');
    }
});
checkGlobal('explicit work year overrides title marker without filtering episode start', function () use ($plugin, $enrich) {
    $title='Synthetic Return'; $candidates=[]; $details=[];
    foreach ([991101=>1982,991102=>2022] as $id=>$year) {
        $candidates[]=['tmdb_id'=>$id,'title'=>$title,'original_title'=>$title,'release_date'=>$year.'-01-01','overview'=>''];
        $details[$id]=Tests\normalizedMovieDetailsFixture($id,$title);
    }
    $service=new CandidateTmdbService(movieCandidates:$candidates,movieDetails:$details);
    $programme=['title'=>$title.' (1982)','production_year'=>2022]; $cache=[];
    assertGlobal(true,Tests\enrich($plugin,$enrich,$programme,$service,$cache)['provider_match'],'Explicit year resolves remake.');
    assertGlobal([2022],$service->movieYears,'Explicit production year wins.');
    assertGlobal([991102],$service->movieDetailIds,'Only confirmed remake details.');
    $service=new CandidateTmdbService();
    $programme=['title'=>'Synthetic Episode (2022)','production_year'=>2023,'episode_nums'=>[['system'=>'xmltv_ns','value'=>'1.2.']]]; $cache=[];
    Tests\enrich($plugin,$enrich,$programme,$service,$cache);
    assertGlobal([null],array_values(array_unique($service->tvYears, SORT_REGULAR)),'Episode year cannot filter series start.');
});
class GlobalCandidateTmdbService extends CandidateTmdbService {
    public array $identityResponses = [];
    public array $translations = [];
    public array $identityRequests = [];
    public array $translationRequests = [];
    public function searchIdentityCandidates(string $type, string $title, ?int $year=null, int $maxPages=4): array {
        $this->identityRequests[] = [$type, $title, $year, $maxPages];
        return $this->identityResponses[$type.':'.$title] ?? ['status'=>'complete','candidates'=>[]];
    }
    public function getIdentityTranslations(string $type, int $id): array {
        $this->translationRequests[] = [$type,$id];
        return $this->translations[$id] ?? ['status'=>'complete','titles'=>[]];
    }
}
checkGlobal('late candidates are evaluated but late equal competitors and incomplete searches abstain', function () use ($plugin,$search) {
    $title='Synthetic Distant Harbor'; $candidates=[];
    for ($i=1;$i<=33;$i++) {
        $name=$i===33 ? $title : 'Unrelated Fiction '.$i;
        $candidates[]=['tmdb_id'=>993000+$i,'name'=>$name,'original_name'=>$name,'first_air_date'=>'2020-01-01','overview'=>''];
    }
    $service=new GlobalCandidateTmdbService(tvDetails:[993033=>Tests\normalizedTvDetailsFixture(993033,$title)]);
    $service->identityResponses['tv:'.$title]=['status'=>'complete','candidates'=>$candidates];
    assertGlobal(993033,Tests\validatedSearch($plugin,$search,$service,$title,'tv')['tmdb_id']??null,'A late exact candidate needs the same validation.');
    $service->identityResponses['tv:'.$title]['candidates'][0]=array_replace($candidates[32],['tmdb_id'=>993001]);
    assertGlobal(null,Tests\validatedSearch($plugin,$search,$service,$title,'tv'),'Late equal competitor cannot disappear.');
    foreach (['error','incomplete'] as $status) {
        $service->identityResponses['tv:'.$title]=['status'=>$status,'candidates'=>[$candidates[32]]];
        assertGlobal(null,Tests\validatedSearch($plugin,$search,$service,$title,'tv'),'Incomplete evidence is not a unique winner.');
    }
});
checkGlobal('provider translations establish full aliases without merging subseries', function () use ($plugin,$search) {
    $title='Synthetic World Atlas';
    $candidates=[['tmdb_id'=>994001,'name'=>'Unrelated Localized Name','original_name'=>'別の作品','first_air_date'=>'2020-01-01','overview'=>'']];
    $service=new GlobalCandidateTmdbService(tvDetails:[994001=>Tests\normalizedTvDetailsFixture(994001,$candidates[0]['name'])]);
    $service->identityResponses['tv:'.$title]=['status'=>'complete','candidates'=>$candidates];
    $service->translations[994001]=['status'=>'complete','titles'=>[$title]];
    assertGlobal(994001,Tests\validatedSearch($plugin,$search,$service,$title,'tv')['tmdb_id']??null,'Native translation title supplies exact work evidence.');
    $service->translations[994001]=['status'=>'complete','titles'=>[$title.' History']];
    assertGlobal(null,Tests\validatedSearch($plugin,$search,$service,$title,'tv'),'A prefix does not prove a subseries.');
    $service->translations[994001]=['status'=>'complete','titles'=>[$title]];
    $service->translations[994002]=['status'=>'complete','titles'=>[$title]];
    $service->identityResponses['tv:'.$title]['candidates'][]=array_replace($candidates[0],['tmdb_id'=>994002]);
    assertGlobal(null,Tests\validatedSearch($plugin,$search,$service,$title,'tv'),'Ambiguous translations abstain.');
});
checkGlobal('same-channel network evidence resolves namesakes without deciding common-network ties', function () use ($plugin,$enrich) {
    $title='Synthetic Observatory'; $candidates=[]; $details=[];
    foreach ([995001=>'Synthetic North',995002=>'Synthetic South'] as $id=>$network) {
        $candidates[]=['tmdb_id'=>$id,'name'=>$title,'original_name'=>$title,'first_air_date'=>'2020-01-01','overview'=>''];
        $details[$id]=Tests\normalizedTvDetailsFixture($id,$title)+['networks'=>[['id'=>$id,'name'=>$network,'logo'=>null]]];
    }
    $service=new GlobalCandidateTmdbService(tvDetails:$details);
    $service->identityResponses['tv:'.$title]=['status'=>'complete','candidates'=>$candidates];
    $cache=[];
    foreach ([['Synthetic North',995001],['Synthetic South',995002],['Unrelated Sender',null],['',null],['Synthetic North',995001]] as [$channel,$expected]) {
        $programme=['title'=>$title,'channel'=>$channel];
        $before=count($service->tvDetailIds);
        $r=Tests\enrich($plugin,$enrich,$programme,$service,$cache,lookupContext:['epg_source_id'=>'synthetic','channel_names'=>$channel === '' ? [] : [$channel]]);
        assertGlobal($expected !== null,$r['provider_match'],'Network is positive additional evidence, missing or mismatching names cannot decide a tie.');
        if ($expected !== null && ! $r['cache_hit']) { assertGlobal($expected,end($cache)['tmdb_id'],'Correct same-channel network identity.'); }
    }
    $details[995002]['networks']=$details[995001]['networks'];
    $service=new GlobalCandidateTmdbService(tvDetails:$details);
    $service->identityResponses['tv:'.$title]=['status'=>'complete','candidates'=>$candidates];
    $programme=['title'=>$title,'channel'=>'same']; $cache=[];
    assertGlobal(false,Tests\enrich($plugin,$enrich,$programme,$service,$cache,lookupContext:['channel_names'=>['Synthetic North']])['provider_match'],'Shared network does not break a tie.');
});
if ($failures !== []) { exit(1); }
echo "Global identity regressions passed.\n";
