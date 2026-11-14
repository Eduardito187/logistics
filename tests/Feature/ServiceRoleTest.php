<?php

declare(strict_types=1);

use App\Support\ServiceRole;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Rol de despliegue del proceso (config/service.php, APP_SERVICE_ROLE): con `monolith` (default) el proceso
 * sirve la app completa, exactamente como siempre; con `telemetry` solo las rutas HTTP del contexto telemetry más las
 * sondas de vida y métricas. Se omite cuando no hay PostgreSQL porque la suite Feature lo requiere.
 */
beforeEach(function (): void {
    // Default explícito: el .env del entorno no debe decidir el resultado.
    config(['service.role' => 'monolith']);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('como monolith sirve todos los contextos y todas las rutas', function (): void {
    expect(ServiceRole::current())->toBe('monolith')
        ->and(ServiceRole::isMonolith())->toBeTrue()
        ->and(ServiceRole::serves('telemetry'))->toBeTrue()
        ->and(ServiceRole::serves('shipments'))->toBeTrue()
        ->and(ServiceRole::allowsPath('admin/login'))->toBeTrue()
        ->and(ServiceRole::allowsPath('api/v1/app/shipments'))->toBeTrue();
});

it('como monolith enforce no registra nada y las rutas responden como siempre', function (): void {
    ServiceRole::enforce(app());

    $kernel = app(HttpKernelContract::class);
    expect($kernel)->toBeInstanceOf(HttpKernel::class)
        ->and($kernel->hasMiddleware(ServiceRole::class))->toBeFalse();

    // Las rutas llegan a su puerta como siempre (401 por falta de token, no 404) y el admin sigue vivo.
    $this->getJson('/api/v1/app/telemetry/fleet/live')->assertStatus(401);
    $this->getJson('/api/v1/health')->assertOk();
    expect($this->get('/admin/login')->status())->not->toBe(404);
});

it('con el rol telemetry solo sirve el contexto telemetry más salud y métricas', function (): void {
    config(['service.role' => 'telemetry']);

    expect(ServiceRole::current())->toBe('telemetry')
        ->and(ServiceRole::isMonolith())->toBeFalse()
        ->and(ServiceRole::serves('telemetry'))->toBeTrue()
        ->and(ServiceRole::serves('shipments'))->toBeFalse()
        ->and(ServiceRole::allowsPath('api/v1/app/telemetry/pings'))->toBeTrue()
        ->and(ServiceRole::allowsPath('/api/v1/integrations/telemetry/pings'))->toBeTrue()
        ->and(ServiceRole::allowsPath('api/v1/app/telemetry'))->toBeTrue()
        ->and(ServiceRole::allowsPath('up'))->toBeTrue()
        ->and(ServiceRole::allowsPath('api/v1/health'))->toBeTrue()
        ->and(ServiceRole::allowsPath('api/metrics'))->toBeTrue()
        ->and(ServiceRole::allowsPath('api/v1/app/shipments'))->toBeFalse()
        ->and(ServiceRole::allowsPath('api/v1/app/telemetry-other'))->toBeFalse()
        ->and(ServiceRole::allowsPath('admin/login'))->toBeFalse()
        ->and(ServiceRole::allowsPath(''))->toBeFalse();
});

it('con el rol telemetry responde 404 a lo que no es del contexto y deja pasar lo suyo', function (): void {
    config(['service.role' => 'telemetry']);
    ServiceRole::enforce(app());
    // Idempotente: una segunda activación no duplica el middleware.
    ServiceRole::enforce(app());

    $kernel = app(HttpKernelContract::class);
    expect($kernel)->toBeInstanceOf(HttpKernel::class)
        ->and($kernel->hasMiddleware(ServiceRole::class))->toBeTrue();

    // Rutas reales de otros contextos y del admin: fuera del rol → 404.
    $this->getJson('/api/v1/app/tracking/shipments')->assertNotFound();
    $this->getJson('/api/v1/channels/promise')->assertNotFound();
    $this->get('/admin/login')->assertNotFound();

    // Rutas del contexto telemetry: llegan a su puerta (401 sin token, no 404).
    $this->getJson('/api/v1/app/telemetry/fleet/live')->assertStatus(401);
    $this->postJson('/api/v1/app/telemetry/pings', [])->assertStatus(401);

    // Salud: siempre servida.
    $this->getJson('/api/v1/health')->assertOk();
});

it('un rol desconocido se comporta como monolith', function (): void {
    config(['service.role' => 'no-such-role']);

    expect(ServiceRole::current())->toBe('monolith')
        ->and(ServiceRole::serves('shipments'))->toBeTrue()
        ->and(ServiceRole::allowsPath('admin/login'))->toBeTrue();
});
