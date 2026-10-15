<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los tests que renderizan páginas completas (admin Filament, SPA) resuelven el layout, que usa
        // `@vite`. En CI el job de tests NO compila assets, así que `public/build/manifest.json` no existe
        // y Filament lanza ViteManifestNotFoundException (500). `withoutVite()` reemplaza Vite por un stub
        // en los tests: el HTML se renderiza sin las etiquetas de assets, suficiente para las aserciones.
        $this->withoutVite();
    }
}
