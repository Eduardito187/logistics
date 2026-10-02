<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unit: PHP puro, sin bootstrap de Laravel (rápidos, se corren todo el tiempo).
// Feature: stack completo contra PostgreSQL real (base logistics_test).
// Los tests Feature de cada package (packages/logistics/<context>/tests/Feature) heredan lo mismo.

$featureDirs = ['Feature', ...(glob(__DIR__.'/../packages/logistics/*/tests/Feature', GLOB_ONLYDIR) ?: [])];

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(...$featureDirs);
