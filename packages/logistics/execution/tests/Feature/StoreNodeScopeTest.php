<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Autorización server-side del alcance de nodo para el rol Tienda (enforce.store.node).
 *
 * Un usuario tienda solo puede operar la recepción en nodo sobre SU nodo (`users.node_id`); cualquier
 * otro nodo objetivo se rechaza con 403 RFC 7807. Los demás roles operan cross-nodo sin restricción.
 *
 * Se omiten SOLO los middlewares de puerta ajenos a lo que se prueba (device-token e idempotencia); el
 * middleware bajo prueba (enforce.store.node) permanece activo. La autenticación se hace con Sanctum.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Body válido para abrir una recepción en nodo con el destino indicado.
 *
 * @return array<string, mixed>
 */
function storeScopeOpenBody(string $shipment, string $origin, string $destination): array
{
    return [
        'shipment_id' => $shipment,
        'origin_node_id' => $origin,
        'destination_node_id' => $destination,
        'origin_node_kind' => 'CD',
        'destination_node_kind' => 'STORE',
        'expected_unit_ids' => ['U-1', 'U-2'],
        'occurred_at' => '2026-10-13T08:00:00+00:00',
    ];
}

/**
 * Crea un usuario con el rol indicado, autenticado por Sanctum, y con el nodo asignado opcional.
 */
function storeScopeActingAs(Role $role, ?string $nodeId): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    // El nodo asignado se setea por fuera de los fillable (lo agrega la migración del otro agente).
    $user->forceFill(['node_id' => $nodeId])->save();

    test()->actingAs($user, 'sanctum');

    return $user;
}

it('permite a un usuario tienda abrir la recepción en su propio nodo', function (): void {
    $shipment = Uuid::generate()->value;
    $origin = Uuid::generate()->value;
    $destination = Uuid::generate()->value;

    storeScopeActingAs(Role::Tienda, $destination);

    test()->postJson('/api/v1/app/node-receptions', storeScopeOpenBody($shipment, $origin, $destination))
        ->assertStatus(201);
});

it('rechaza con 403 a un usuario tienda sobre un nodo que no es el suyo', function (): void {
    $shipment = Uuid::generate()->value;
    $origin = Uuid::generate()->value;
    $destination = Uuid::generate()->value;

    storeScopeActingAs(Role::Tienda, Uuid::generate()->value); // nodo asignado distinto del destino

    test()->postJson('/api/v1/app/node-receptions', storeScopeOpenBody($shipment, $origin, $destination))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('rechaza con 403 a un usuario tienda sin nodo asignado', function (): void {
    $shipment = Uuid::generate()->value;
    $origin = Uuid::generate()->value;
    $destination = Uuid::generate()->value;

    storeScopeActingAs(Role::Tienda, null);

    test()->postJson('/api/v1/app/node-receptions', storeScopeOpenBody($shipment, $origin, $destination))
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

it('no restringe a un usuario supervisor (opera cross-nodo)', function (): void {
    $shipment = Uuid::generate()->value;
    $origin = Uuid::generate()->value;
    $destination = Uuid::generate()->value;

    // Supervisor sin nodo asignado: pasa el scope y abre la recepción en cualquier nodo.
    storeScopeActingAs(Role::Supervisor, null);

    test()->postJson('/api/v1/app/node-receptions', storeScopeOpenBody($shipment, $origin, $destination))
        ->assertStatus(201);
});
