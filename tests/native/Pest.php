<?php

require_once __DIR__.'/bootstrap.php';
pest()->extend(EnricherNativeTestCase::class)
    ->use(\Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in(__DIR__);
