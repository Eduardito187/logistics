<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

/**
 * Candado de seguridad del tiempo real: en producción nunca se difunde por sockets con las credenciales
 * públicas de desarrollo (`dismaclocalkey` / `dismaclocalsecret`). Con el secreto por defecto, un tercero
 * podría firmar canales privados; por eso en producción, si el transporte está activo con esos defaults, el
 * broadcasting se apaga (fail-safe).
 */
it('apaga el tiempo real en producción cuando la clave o el secreto son los defaults públicos', function (): void {
    expect(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'pusher', 'dismaclocalkey', 'dismaclocalsecret'))->toBeTrue()
        ->and(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'pusher', 'prod-key', 'dismaclocalsecret'))->toBeTrue()
        ->and(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'pusher', 'dismaclocalkey', 'prod-secret'))->toBeTrue();
});

it('no apaga el tiempo real cuando hay credenciales propias', function (): void {
    expect(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'pusher', 'prod-key', 'prod-secret'))->toBeFalse();
});

it('no interviene fuera de producción ni con otro transporte', function (): void {
    // Dev usa los defaults a propósito.
    expect(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(false, 'pusher', 'dismaclocalkey', 'dismaclocalsecret'))->toBeFalse()
        // Sin transporte de sockets no hay nada que apagar.
        ->and(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'null', 'dismaclocalkey', 'dismaclocalsecret'))->toBeFalse()
        ->and(AppServiceProvider::realtimeUsesPublicDefaultsInProduction(true, 'log', 'dismaclocalkey', 'dismaclocalsecret'))->toBeFalse();
});
