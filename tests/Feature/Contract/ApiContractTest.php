<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateChannel;
use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spectator\Spectator;

/*
| Tests de contrato OpenAPI (V-14).
|
| Validan request y response de una muestra representativa de las tres puertas (app, channels,
| integrations) contra openapi/v1.yaml con hotmeteor/spectator. El objetivo es el CONTRATO (forma del
| payload y de la respuesta), así que se desactiva solo el middleware de credenciales de cada puerta y se
| deja activo el middleware de spectator. Corre contra logistics_test (RefreshDatabase).
*/

beforeEach(function (): void {
    Spectator::using('v1.yaml');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

// ── system ───────────────────────────────────────────────────────────────
it('valida GET /health contra el contrato', function (): void {
    $this->getJson('/api/v1/health')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

// ── puerta app: autenticación y dispositivos ───────────────────────────────
it('valida POST /app/auth/login contra el contrato', function (): void {
    User::factory()->create(['email' => 'driver@example.com', 'password' => 'secret-pass']);

    $this->postJson('/api/v1/app/auth/login', [
        'email' => 'driver@example.com',
        'password' => 'secret-pass',
        'device' => [
            'fingerprint' => 'test-device-fingerprint',
            'platform' => 'android',
            'name' => 'Pixel 7',
        ],
    ])
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida GET /app/auth/me contra el contrato', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/app/auth/me')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida GET /app/devices contra el contrato', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/app/devices')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

// ── puerta app: listados con paginación (E-02) ─────────────────────────────
it('valida GET /app/shipments contra el contrato (sin paginación)', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/app/shipments')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida GET /app/shipments con paginación por cursor contra el contrato', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/app/shipments?per_page=2&cursor=')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida GET /app/carriers con paginación por página contra el contrato', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/app/carriers?page=1&per_page=10')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

// ── puerta channels: nuevos endpoints de canales ───────────────────────────
it('valida GET /channels/promises/delivery contra el contrato', function (): void {
    $this->withoutMiddleware(AuthenticateChannel::class);

    $this->getJson('/api/v1/channels/promises/delivery?municipality_id=00000000-0000-7000-8000-0000000000aa')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida GET /channels/promises/delivery paginado contra el contrato', function (): void {
    $this->withoutMiddleware(AuthenticateChannel::class);

    $this->getJson('/api/v1/channels/promises/delivery?municipality_id=00000000-0000-7000-8000-0000000000aa&per_page=5&page=1')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

it('valida POST /channels/shipments/slot-reservation contra el contrato', function (): void {
    $this->withoutMiddleware(AuthenticateChannel::class);

    // Envío inexistente: el dominio responde 404 (documentado). Se valida la forma del request y de
    // la respuesta de error contra el contrato.
    $this->postJson('/api/v1/channels/shipments/slot-reservation', [
        'shipment_id' => '00000000-0000-7000-8000-000000000001',
        'date' => '2026-12-01',
        'window_start' => '09:00',
        'window_end' => '13:00',
    ])
        ->assertValidRequest()
        ->assertValidResponse();
});

it('valida GET /channels/auth/ping contra el contrato', function (): void {
    $this->withoutMiddleware(AuthenticateChannel::class);

    $this->getJson('/api/v1/channels/auth/ping')
        ->assertValidRequest()
        ->assertValidResponse(200);
});

// ── puerta integrations ─────────────────────────────────────────────────────
it('valida GET /integrations/auth/ping contra el contrato', function (): void {
    $this->withoutMiddleware(AuthenticateServiceClient::class);

    $this->getJson('/api/v1/integrations/auth/ping')
        ->assertValidRequest()
        ->assertValidResponse(200);
});
