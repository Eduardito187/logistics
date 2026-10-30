<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MetricsController;
use Illuminate\Support\Facades\Route;

/*
| Integrador de rutas de la API v1 (P1-API-01).
|
| Tres "puertas" de API (ver docs/architecture.md):
|   /api/v1/app/*           → app interna Flutter (token por usuario + dispositivo)
|   /api/v1/channels/*      → app de clientes, web, call center (credenciales por canal + rate limit)
|   /api/v1/integrations/*  → SAP, transportistas, webhooks (credenciales de servicio)
|
| Alias de middleware registrados en App\Providers\AuthServiceProvider:
|   device        → EnsureDeviceToken  (token de usuario ligado a un dispositivo vigente)
|   role:<r1,r2>  → EnsureRole         (el usuario tiene alguno de esos roles)
|   require.ability:<a1> → EnsureAbility (el token tiene esas habilidades)
|   channel.auth  → AuthenticateChannel (X-Channel-Key / X-Channel-Secret)
|   service.auth  → AuthenticateServiceClient (X-Client-Id / X-Client-Secret)
| Rate limiter 'channels' (por canal) también en AuthServiceProvider.
|
| Cada fragmento de contexto (routes/api/<ctx>.php) devuelve un mapa puerta => closure con sus rutas
| relativas a la puerta. Aquí se montan las tres puertas con su prefijo + middleware y se invoca, en
| cada una, la closure de cada contexto que declara esa puerta.
|
| El contrato vive en openapi/v1.yaml: todo endpoint nuevo se agrega ahí en el mismo cambio.
*/

// ── Health público (sin credenciales): sonda de vida para balanceadores y apps. ──
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');
});

// ── Métricas Prometheus (/api/metrics): señales RED/USE para el monitoreo (auditoría M-6). ──
// NO es una puerta de negocio: se protege dentro del controlador por token (METRICS_TOKEN) y/o lista
// de IPs permitidas (METRICS_IP_ALLOWLIST), fail-closed. Sin credenciales de Sanctum/canal porque el
// scraper es un consumidor de infraestructura, no un cliente de la API.
Route::get('metrics', MetricsController::class)->name('api.metrics');

// ── Autenticación de las tres puertas (login público + logout/refresh/me, devices, pings). ──
// Fichero autocontenido: define sus propios grupos de puerta porque el login es público (solo
// throttle) y no puede vivir bajo el middleware auth:sanctum de la puerta app.
require __DIR__.'/api/auth.php';

/*
| Fragmentos de contexto: cada require devuelve ['app' => closure, 'channels' => ..., 'integrations' => ...].
| Se cargan una sola vez; las closures se invocan dentro del grupo de su puerta (así heredan prefijo
| y middleware). Un contexto solo aparece en las puertas que declara.
*/
$contextMaps = [
    require __DIR__.'/api/access.php',
    require __DIR__.'/api/accounts.php',
    require __DIR__.'/api/capacity.php',
    require __DIR__.'/api/carriers.php',
    require __DIR__.'/api/cart.php',
    require __DIR__.'/api/catalog.php',
    require __DIR__.'/api/coverage.php',
    require __DIR__.'/api/execution.php',
    require __DIR__.'/api/fleet.php',
    require __DIR__.'/api/fleetops.php',
    require __DIR__.'/api/inbound.php',
    require __DIR__.'/api/inventory.php',
    require __DIR__.'/api/labels.php',
    require __DIR__.'/api/network.php',
    require __DIR__.'/api/notifications.php',
    require __DIR__.'/api/orders.php',
    require __DIR__.'/api/outbox.php',
    require __DIR__.'/api/payments.php',
    require __DIR__.'/api/planning.php',
    require __DIR__.'/api/pricing.php',
    require __DIR__.'/api/promise.php',
    require __DIR__.'/api/returns.php',
    require __DIR__.'/api/sap-connector.php',
    require __DIR__.'/api/seller-portal.php',
    require __DIR__.'/api/sellers.php',
    require __DIR__.'/api/settings.php',
    require __DIR__.'/api/settlements.php',
    require __DIR__.'/api/shipments.php',
    require __DIR__.'/api/telemetry.php',
    require __DIR__.'/api/tracking.php',
];

/**
 * Invoca, para la puerta dada, la closure de cada contexto que la declara. Cada fragmento devuelve
 * un mapa puerta => closure; las puertas no declaradas se omiten sin error.
 *
 * @param list<mixed> $contextMaps
 */
$mountGate = static function (string $gate) use ($contextMaps): void {
    foreach ($contextMaps as $map) {
        if (!is_array($map)) {
            continue;
        }

        $register = $map[$gate] ?? null;

        if (is_callable($register)) {
            $register();
        }
    }
};

// El middleware `idempotency` se aplica a las tres puertas: deduplica reintentos de escritura
// de la app offline por cabecera `Idempotency-Key` (no-op cuando la cabecera no viaja, así que es
// inocuo para los clientes que no la envían). Ver App\Http\Middleware\IdempotentRequests.

// ── Puerta 1: app interna Flutter · /api/v1/app/* ──────────────────────────
// Token Sanctum por usuario ligado a un dispositivo vigente.
Route::prefix('v1/app')->name('api.v1.app.')
    ->middleware(['auth:sanctum', 'device', 'idempotency'])
    ->group(function () use ($mountGate): void {
        $mountGate('app');
    });

// ── Puerta 2: canales (clientes/web/call center) · /api/v1/channels/* ──────
// Credenciales por canal + rate limit por canal (el limiter lee el canal resuelto).
Route::prefix('v1/channels')->name('api.v1.channels.')
    ->middleware(['channel.auth', 'throttle:channels', 'idempotency'])
    ->group(function () use ($mountGate): void {
        $mountGate('channels');
    });

// ── Puerta 3: integraciones (SAP/transportistas/webhooks) · /api/v1/integrations/* ──
// Credenciales de servicio; el scope, si aplica, se exige por ruta con service.auth:<scope>.
Route::prefix('v1/integrations')->name('api.v1.integrations.')
    ->middleware(['service.auth', 'idempotency'])
    ->group(function () use ($mountGate): void {
        $mountGate('integrations');
    });
