<?php

declare(strict_types=1);

/*
 * Configuración de blade-heroicons (equivale a `vendor:publish --tag=blade-heroicons-config`).
 *
 * El panel usa el set propio DisTrack (`ds-*`) en todo su código y en los 222 alias de Filament
 * (App\Support\Icons\DistrackIconSet). Quedan unos pocos íconos que Filament dibuja SIN alias
 * (selector de tema: sun/moon/computer-desktop; calendario del DateTimePicker; lupa del RichEditor).
 * Para que también sean DisTrack, el set `heroicons` busca primero en `resources/svg/heroicons-override/`
 * (copias de los SVG ds-* con el nombre de archivo de Heroicons: o-, s-, m-, c-) y, si no encuentra el
 * archivo, cae al SVG original del paquete. Blade Icons recorre los `paths` en orden.
 */
return [

    'prefix' => 'heroicon',

    'paths' => [
        resource_path('svg/heroicons-override'),
        base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg'),
    ],

    'fallback' => '',

    'class' => '',

    'attributes' => [],

];
