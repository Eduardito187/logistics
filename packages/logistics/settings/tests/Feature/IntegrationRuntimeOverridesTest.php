<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;
use Logistics\Settings\Infrastructure\Integration\IntegrationRuntimeOverrides;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejercita IntegrationRuntimeOverrides: overlaya en config() las integraciones guardadas desde el panel
 * (ruteo, SAP, almacenamiento) con fallback a env, y el refresco de workers de cola. Cada escenario
 * guarda una versión por el bus CQRS, construye un overrides fresco y comprueba la config resultante.
 * Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    cache()->flush();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Publica una versión de una clave de integración (valor crudo tal cual lo guardaría el panel).
 *
 * @param array<string, mixed> $value
 */
function overridesSave(string $key, array $value): void
{
    app(CommandBus::class)->dispatch(new SetSettingCommand($key, $value, null, 'tester'));
}

/**
 * Reinicia el reloj estático del refresco de workers para que el próximo refresco sí se aplique.
 */
function overridesResetWorkerClock(): void
{
    $property = new ReflectionProperty(IntegrationRuntimeOverrides::class, 'lastWorkerRefresh');
    $property->setValue(null, 0);
}

it('overlaya el ruteo guardado sobre logistics-planning, recortando la barra final del host', function (): void {
    overridesSave(IntegrationKeys::ROUTING, [
        'enabled' => true,
        'osrm_host' => 'https://osrm.example.com/',
        'osrm_timeout' => 9,
        'vroom_url' => 'https://vroom.example.com',
        'backend' => 'vroom',
    ]);

    app(IntegrationRuntimeOverrides::class)->apply();

    expect(config('logistics-planning.osrm_host'))->toBe('https://osrm.example.com')
        ->and(config('logistics-planning.osrm_timeout'))->toBe(9)
        ->and(config('logistics-planning.routing.vroom_url'))->toBe('https://vroom.example.com')
        ->and(config('logistics-planning.routing.backend'))->toBe('vroom');
});

it('no overlaya el ruteo cuando está deshabilitado', function (): void {
    $before = config('logistics-planning.osrm_host');
    overridesSave(IntegrationKeys::ROUTING, ['enabled' => false, 'osrm_host' => 'https://no.example.com']);

    app(IntegrationRuntimeOverrides::class)->apply();

    expect(config('logistics-planning.osrm_host'))->toBe($before);
});

it('overlaya el conector SAP habilitado, descifrando la contraseña y filtrando los eventos vacíos', function (): void {
    overridesSave(IntegrationKeys::SAP, [
        'enabled' => true,
        'base_url' => 'https://sap.example.com/odata',
        'username' => 'svc-logistics',
        'password' => Crypt::encryptString('s3cret'),
        'orders_path' => '/Orders',
        'stock_transfers_path' => '/Transfers',
        'timeout' => 8,
        'events_path' => '/Events',
        'notify_events' => ['order.paid', '', 'order.delivered'],
        'max_attempts' => 5,
        'notification_timeout' => 7,
    ]);

    app(IntegrationRuntimeOverrides::class)->apply();

    expect(config('logistics-sap-connector.sap.enabled'))->toBeTrue()
        ->and(config('logistics-sap-connector.sap.base_url'))->toBe('https://sap.example.com/odata')
        ->and(config('logistics-sap-connector.sap.username'))->toBe('svc-logistics')
        ->and(config('logistics-sap-connector.sap.password'))->toBe('s3cret')
        ->and(config('logistics-sap-connector.sap.orders_path'))->toBe('/Orders')
        ->and(config('logistics-sap-connector.sap.stock_transfers_path'))->toBe('/Transfers')
        ->and(config('logistics-sap-connector.sap.timeout'))->toBe(8)
        ->and(config('logistics-sap-connector.sap.events_path'))->toBe('/Events')
        ->and(config('logistics-sap-connector.notifications.events'))->toBe(['order.paid', 'order.delivered'])
        ->and(config('logistics-sap-connector.notifications.max_attempts'))->toBe(5)
        ->and(config('logistics-sap-connector.notifications.timeout'))->toBe(7);
});

it('apaga el conector SAP y corta antes de overlayar sus credenciales cuando se guardó deshabilitado', function (): void {
    config()->set('logistics-sap-connector.sap.base_url', 'https://env.example.com');
    overridesSave(IntegrationKeys::SAP, ['enabled' => false]);

    app(IntegrationRuntimeOverrides::class)->apply();

    expect(config('logistics-sap-connector.sap.enabled'))->toBeFalse()
        ->and(config('logistics-sap-connector.sap.base_url'))->toBe('https://env.example.com');
});

it('overlaya las credenciales S3 descifradas sobre cada disco con driver s3', function (): void {
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'bucket' => 'own-bucket',
        'region' => 'env-region',
    ]);

    overridesSave(IntegrationKeys::STORAGE, [
        'enabled' => true,
        'key' => Crypt::encryptString('AKIAEXAMPLE'),
        'secret' => Crypt::encryptString('super-secret'),
        'region' => 'us-east-1',
        'endpoint' => 'https://minio.example.com',
        'bucket' => 'general-bucket',
        'use_path_style_endpoint' => true,
    ]);

    app(IntegrationRuntimeOverrides::class)->apply();

    $disk = config('filesystems.disks.s3');
    expect($disk['key'])->toBe('AKIAEXAMPLE')
        ->and($disk['secret'])->toBe('super-secret')
        ->and($disk['region'])->toBe('us-east-1')
        ->and($disk['endpoint'])->toBe('https://minio.example.com')
        ->and($disk['use_path_style_endpoint'])->toBeTrue()
        // El bucket propio del disco se preserva: el general solo rellena discos sin bucket.
        ->and($disk['bucket'])->toBe('own-bucket');
});

it('el refresco de worker aplica una vez y omite las llamadas dentro de la ventana', function (): void {
    overridesResetWorkerClock();
    $calls = 0;
    $resolve = function () use (&$calls): IntegrationRuntimeOverrides {
        $calls++;

        return app(IntegrationRuntimeOverrides::class);
    };

    IntegrationRuntimeOverrides::refreshForWorker($resolve, 30);
    IntegrationRuntimeOverrides::refreshForWorker($resolve, 30);

    expect($calls)->toBe(1);
});

it('el refresco de worker nunca lanza si el resolver falla', function (): void {
    overridesResetWorkerClock();

    IntegrationRuntimeOverrides::refreshForWorker(static function (): IntegrationRuntimeOverrides {
        throw new RuntimeException('sin DB');
    }, 30);

    // Si el resolver lanza, refreshForWorker NO debe propagar: de hacerlo, este test fallaría aquí.
    // Y el reloj igual avanzó (se fija antes del try), así que no reintenta en bucle dentro de la ventana.
    $property = new ReflectionProperty(IntegrationRuntimeOverrides::class, 'lastWorkerRefresh');
    expect($property->getValue())->toBeGreaterThan(0);
});
