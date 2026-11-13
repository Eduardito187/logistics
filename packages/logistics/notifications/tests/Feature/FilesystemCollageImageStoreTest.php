<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Logistics\Notifications\Infrastructure\Image\FilesystemCollageImageStore;

it('guarda el PNG del collage en el disco indicado y devuelve una URL', function (): void {
    Storage::fake('collages-disk');

    $store = new FilesystemCollageImageStore('collages-disk');

    $url = $store->store('shp-1', 'bytes-png');

    Storage::disk('collages-disk')->assertExists('collages/shp-1.png');
    expect(Storage::disk('collages-disk')->get('collages/shp-1.png'))->toBe('bytes-png')
        ->and($url)->toBeString()
        ->and($url)->toContain('shp-1.png');
});

it('es idempotente por envío: el mismo id escribe siempre la misma ruta', function (): void {
    Storage::fake('collages-disk');

    $store = new FilesystemCollageImageStore('collages-disk');

    $store->store('shp-1', 'primero');
    $store->store('shp-1', 'segundo');

    expect(Storage::disk('collages-disk')->get('collages/shp-1.png'))->toBe('segundo')
        ->and(Storage::disk('collages-disk')->allFiles('collages'))->toHaveCount(1);
});

it('respeta el directorio configurado', function (): void {
    Storage::fake('collages-disk');

    $store = new FilesystemCollageImageStore('collages-disk', 'push');

    $store->store('shp-1', 'bytes');

    Storage::disk('collages-disk')->assertExists('push/shp-1.png');
});

it('normaliza un id de envío con caracteres no seguros', function (): void {
    Storage::fake('collages-disk');

    $store = new FilesystemCollageImageStore('collages-disk');

    $store->store('shp/../ 1 raro!', 'bytes');

    Storage::disk('collages-disk')->assertExists('collages/shp1raro.png');
});

it('cae a un nombre de respaldo cuando el id no deja caracteres seguros', function (): void {
    Storage::fake('collages-disk');

    $store = new FilesystemCollageImageStore('collages-disk');

    $store->store('!!!', 'bytes');

    Storage::disk('collages-disk')->assertExists('collages/shipment.png');
});

it('usa el disco por defecto cuando el disco configurado es vacío', function (): void {
    Storage::fake('local');

    $store = new FilesystemCollageImageStore('');

    $store->store('shp-1', 'bytes');

    Storage::disk('local')->assertExists('collages/shp-1.png');
})->skip(
    // En closure: se evalúa al CORRER (app booteada), no en la colección (donde `config` aún no existe).
    fn (): bool => config('filesystems.default') !== 'local',
    'El disco por defecto del entorno no es local.',
);
