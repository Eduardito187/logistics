<?php

declare(strict_types=1);

/*
 * Configuración de Blade Icons (equivale a `php artisan vendor:publish --tag=blade-icons`).
 *
 * Set propio DisTrack: todos los íconos del panel viven en `resources/svg/distrack/` (dentro del repo,
 * CERO CDN) y se usan con el prefijo `ds-` + nombre de archivo: `->icon('ds-truck')` o
 * `@svg('ds-map-pin', 'h-5 w-5')`. Cada SVG trae su grupo animable `ds-i ds-m-*` y su acento `ds-accent`
 * (animación en resources/css/filament/admin/theme.css). Los íconos internos de Filament se mapean en
 * App\Support\Icons\DistrackIconSet y los pocos que Filament dibuja sin alias, en config/blade-heroicons.php.
 * Guía completa: docs/filament-iconos-custom-animados.md.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Sets de íconos
    |--------------------------------------------------------------------------
    |
    | `path` es relativo a la raíz del proyecto. El `prefix` no lleva guiones.
    |
    */

    'sets' => [

        // Mismo nombre que el Heroicon que reemplaza: `heroicon-o-truck` → `ds-truck`.
        'distrack' => [
            'path' => 'resources/svg/distrack',
            'disk' => '',
            'prefix' => 'ds',
            'fallback' => '',
            'class' => '',
            'attributes' => [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Valores globales
    |--------------------------------------------------------------------------
    |
    | Sin clases ni atributos globales: Filament y Tailwind controlan el tamaño de cada ícono.
    |
    */

    'class' => '',

    'attributes' => [],

    'fallback' => '',

    'components' => [
        'disabled' => false,
        'default' => 'icon',
    ],

];
