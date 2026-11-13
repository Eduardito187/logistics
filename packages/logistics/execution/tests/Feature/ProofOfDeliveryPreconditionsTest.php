<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Precondiciones del POD por API: una precondición faltante responde 422 con el motivo (nunca 500), y la foto
 * de entrega es OBLIGATORIA. Antes, cualquier regla de negocio del dominio caía en la rama "default" del
 * renderer y daba 500 ("se colgó"), que no le dice nada al chofer.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    // La foto del POD debe existir en el disco de evidencia (se rechaza una referencia no subida).
    Storage::fake('pod');
    Storage::disk('pod')->put('evidence/pod.jpg', 'jpg');

    // Rol de campo (no Tienda): el POD opera cross-nodo, sin el chequeo de nodo de la tienda.
    $user = User::factory()->create();
    $user->assignRole(Role::Chofer);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function podBody(string $shipment, string $unit, array $overrides = []): array
{
    return array_merge([
        'shipment_id' => $shipment,
        'unit_id' => $unit,
        'confirmation' => 'Firma receptor',
        'photo_path' => 'evidence/pod.jpg',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => '2026-10-16T11:00:00+00:00',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'idempotency_key' => 'pod-'.$unit,
    ], $overrides);
}

it('sin foto el POD cierra 200 y queda pendiente de foto (opción A, POD asíncrono)', function (): void {
    $shipment = Uuid::generate()->value;
    $unit = Uuid::generate()->value;

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new RecordPreparationScan($shipment, Uuid::generate()->value, $unit, -16.5, -68.15, 'dev', 'usr', '2026-10-16T10:00:00+00:00', 'prep-'.$unit));
    $bus->dispatch(new RecordLoadScan($shipment, $unit, -16.5, -68.15, 'dev', 'usr', '2026-10-16T10:30:00+00:00', 'load-'.$unit));

    $body = podBody($shipment, $unit);
    unset($body['photo_path']);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', $body)
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.pod_photo_pending', true);
});

it('sin entrega abierta para el envío, el POD responde 422 con el motivo (no 500)', function (): void {
    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podBody(Uuid::generate()->value, Uuid::generate()->value))
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation')
        ->assertJsonPath('detail', fn (string $m): bool => str_contains($m, 'entrega'));
});

it('el POD sobre una entrega ya cerrada responde 422 (transición inválida), no 500', function (): void {
    $shipment = Uuid::generate()->value;
    $unit = Uuid::generate()->value;

    // Flujo vigente: la entrega abierta ya permite el POD directo (no se exige escaneo de carga). Se abre
    // con la preparación y se entrega una vez.
    app(CommandBus::class)->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: Uuid::generate()->value,
        unitId: $unit,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'prep-'.$unit,
    ));
    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podBody($shipment, $unit))->assertOk();

    // Un SEGUNDO POD (otra idempotency-key) sobre la entrega ya DELIVERED es una transición inválida: 422
    // con el motivo de regla de negocio, nunca 500.
    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podBody($shipment, $unit, ['idempotency_key' => 'pod2-'.$unit]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');
});
