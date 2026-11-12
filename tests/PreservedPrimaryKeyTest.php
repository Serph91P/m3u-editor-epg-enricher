<?php

require dirname(__DIR__).'/Services/PreservedPrimaryKey.php';
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryKey as Key;

$a = Key::slot(1, 'synthetic', 100, 200);
foreach ([[2, 'synthetic', 100, 200], [1, 'other', 100, 200], [1, 'synthetic', 101, 200], [1, 'synthetic', 100, 201]] as $slot) {
    if ($a === Key::slot(...$slot)) { throw new RuntimeException('Slot must be source/channel/time scoped.'); }
}
if ($a !== hash('sha256', '[1,"synthetic",100,200]')) { throw new RuntimeException('Exact canonical slot encoding.'); }
$p = ['title' => 'Synthetic', 'icon' => 'https://example.invalid/a.jpg'];
$before = Key::identity($p);
$p['icon'] = 'https://example.invalid/b.jpg';
if ($before !== Key::identity($p)) { throw new RuntimeException('URL is independently guarded.'); }
foreach (['title', 'subtitle', 'episode_num', 'episode_nums', 'production_year'] as $field) {
    $changed = $p; $changed[$field] = $field === 'episode_nums' ? [['system' => 'xmltv_ns', 'value' => '1.1.']] : 'Other';
    if ($before === Key::identity($changed)) { throw new RuntimeException('Identity must guard '.$field); }
}
if (Key::identity([]) !== Key::identity(['title' => null])) { throw new RuntimeException('Missing values are null.'); }
if (Key::identity(['episode_nums' => ['a','b']]) === Key::identity(['episode_nums' => ['b','a']])) { throw new RuntimeException('Order matters.'); }
print "Preserved Primary key cases passed.\n";
