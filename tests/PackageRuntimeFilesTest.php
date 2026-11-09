<?php

$root = dirname(__DIR__);
$dist = $root.'/dist';
if (! is_dir($dist)) { mkdir($dist, 0700, true); }
$required = ['Plugin.php', 'plugin.json', 'README.md'];
foreach (glob($root.'/Services/*.php') as $file) { $required[] = 'Services/'.basename($file); }
foreach (['normal' => ['bash', $root.'/scripts/package-plugin.sh', 'runtime-test.zip'], 'fallback' => ['php', $root.'/scripts/package-plugin-fallback.php', $dist.'/runtime-fallback-test.zip']] as $mode => $command) {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) { fclose($pipe); }
    if (proc_close($process) !== 0) { throw new RuntimeException($output); }
    $file = $dist.($mode === 'normal' ? '/runtime-test.zip' : '/runtime-fallback-test.zip');
    $zip = new ZipArchive;
    if ($zip->open($file) !== true) { throw new RuntimeException('Archive missing.'); }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) { if (! str_ends_with($zip->getNameIndex($i), '/')) { $names[] = $zip->getNameIndex($i); } }
    sort($names); $expected = $required; sort($expected);
    if ($names !== $expected) { throw new RuntimeException($mode.' must contain exactly runtime services and root metadata: '.json_encode($names)); }
    foreach ($required as $path) {
        if ($zip->getFromName($path) !== file_get_contents($root.'/'.$path)) { throw new RuntimeException('Packaged source differs: '.$path); }
    }
    $zip->close();
    unlink($file);
    if (is_file($file.'.sha256')) { unlink($file.'.sha256'); }
}
$workflow = file_get_contents($root.'/.github/workflows/release.yml');
if (! str_contains($workflow, 'bash scripts/package-plugin.sh')) { throw new RuntimeException('Release must use the tested packer, not a second file list.'); }
echo "Both package paths contain every runtime service and release uses the tested packer.\n";
