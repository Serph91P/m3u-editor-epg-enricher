<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/bootstrap.php';
pest()->extend(EnricherNativeTestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);
