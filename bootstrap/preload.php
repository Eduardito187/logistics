<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OPcache preload — logistics (OPT-IN, desactivado por defecto)
|--------------------------------------------------------------------------
|
| Este script precompila el bytecode de las clases en memoria compartida de
| OPcache al arrancar PHP-FPM, para que NO se parseen en el primer request de
| cada worker. Se activa poniendo en php.prod.ini:
|
|     opcache.preload = /var/www/html/bootstrap/preload.php
|     opcache.preload_user = logistics
|
| Está DESACTIVADO a propósito (ver php.prod.ini). Riesgos a auditar antes de
| encenderlo en producción, documentados aquí para no activarlo a ciegas:
|
|  - Service providers de los packages (packages/logistics/*): el preload linkea
|    clases de forma permanente para la vida del master FPM. Si una clase extiende
|    o implementa algo que NO quedó precargado, PHP la descarta con un warning y la
|    recompila en runtime (degradación silenciosa, no error). Por eso acá se usa
|    `opcache_compile_file()` (solo compila; NO ejecuta el código de nivel superior
|    ni instancia providers), el modo conservador y seguro.
|  - El preload se lee UNA vez al arrancar el master; cambiar código exige reiniciar
|    PHP-FPM (en prod ya se reinicia el contenedor en cada deploy, así que encaja).
|  - Medir antes/después: el beneficio real depende del tamaño del classmap y del
|    patrón de tráfico. Validar con load test (Fase 4) antes de dejarlo fijo.
|
| Estrategia conservadora: se recorre el classmap optimizado de Composer y se
| compila cada archivo con `opcache_compile_file()`, saltando lo que no aporta en
| producción (tests, migraciones, seeders, factories). Cada compilación va envuelta
| para que un archivo problemático nunca tumbe el arranque del servidor.
*/

$root = dirname(__DIR__);
$classmap = $root.'/vendor/composer/autoload_classmap.php';

if (!is_file($classmap)) {
    // Sin autoloader optimizado (`composer dump-autoload --optimize`) no hay nada
    // que precargar; se sale sin romper el arranque.
    return;
}

/** @var array<string, string> $classes */
$classes = require_once $classmap;

// Patrones de rutas que NO se precargan: no sirven en el request de producción.
$skipPatterns = [
    '/tests/',
    '/database/migrations/',
    '/database/seeders/',
    '/database/factories/',
];

$compiled = 0;
$skipped = 0;

foreach ($classes as $file) {
    if (!is_string($file) || !is_file($file)) {
        $skipped++;

        continue;
    }

    $normalized = str_replace('\\', '/', $file);
    foreach ($skipPatterns as $pattern) {
        if (str_contains($normalized, $pattern)) {
            $skipped++;

            continue 2;
        }
    }

    try {
        // `@` + try/catch: un archivo que no linkee jamás debe abortar el preload.
        if (@opcache_compile_file($file)) {
            $compiled++;
        } else {
            $skipped++;
        }
    } catch (Throwable) {
        $skipped++;
    }
}

if (\PHP_SAPI === 'cli') {
    fwrite(\STDERR, "[preload] compiladas={$compiled} saltadas={$skipped}\n");
}
