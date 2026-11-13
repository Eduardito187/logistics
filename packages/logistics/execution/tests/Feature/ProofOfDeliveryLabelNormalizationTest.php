<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Labels\Application\Contract\LabelUnitResolver;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Normalización del id escaneado en el POD: el chofer entrega escaneando la ETIQUETA, cuyo QR NO lleva el
 * `shipment_unit.id`. La app manda el `label_id` (ULID) como `unit_id`/`unit_ids[]`; el borde del POD lo
 * resuelve ({@see LabelUnitResolver} vía QueryBus) al `shipment_unit.id` canónico ANTES de grabar el escaneo.
 * Si no resuelve (ya es un id de bulto, o es desconocido) se graba el id tal cual (tolerante/offline-first).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    // La foto es opcional (opción A): acá se omite para aislar la normalización del bulto.
    Storage::fake('pod');

    $user = User::factory()->create();
    $user->assignRole(Role::Chofer);
    $this->actingAs($user, 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Fake del resolvedor etiqueta→bulto: resuelve solo los `label_id` del mapa; el resto queda `resolved=false`
 * (igual que un id de bulto ya canónico o uno desconocido).
 *
 * @param array<string, array{0: string, 1: string}> $map label_id => [unitId, shipmentId]
 */
function fakeLabelResolver(array $map): LabelUnitResolver
{
    return new class($map) implements LabelUnitResolver
    {
        /**
         * @param array<string, array{0: string, 1: string}> $map
         */
        public function __construct(private array $map) {}

        public function resolve(string $labelId): LabelUnitView
        {
            if (isset($this->map[$labelId])) {
                [$unitId, $shipmentId] = $this->map[$labelId];

                return new LabelUnitView($labelId, true, $unitId, $shipmentId, null, null, null, null, null, null, null);
            }

            return new LabelUnitView($labelId, false, null, null, null, null, null, null, null, null, null);
        }
    };
}

/**
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function podLabelBody(string $shipment, array $overrides = []): array
{
    return array_merge([
        'shipment_id' => $shipment,
        'confirmation' => 'Firma receptor',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => '2026-10-16T11:00:00+00:00',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
    ], $overrides);
}

/**
 * Abre la entrega del envío (una preparación basta: el POD vale desde Pending/Prepared).
 */
function openDeliveryForPod(string $shipment, string $anchorUnit): void
{
    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new RecordPreparationScan(
        $shipment,
        Uuid::generate()->value,
        $anchorUnit,
        -16.5,
        -68.15,
        'dev',
        'usr',
        '2026-10-16T10:00:00+00:00',
        'prep-'.$anchorUnit,
    ));
}

it('el POD con un label_id como unit_id graba el shipment_unit.id canónico (no el label_id)', function (): void {
    $shipment = Uuid::generate()->value;
    $canonicalUnit = Uuid::generate()->value;
    $labelId = '01M73HK0D0C8YB33Z48ADGXX9T';

    $this->app->bind(LabelUnitResolver::class, fn () => fakeLabelResolver([
        $labelId => [$canonicalUnit, $shipment],
    ]));

    openDeliveryForPod($shipment, $canonicalUnit);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podLabelBody($shipment, [
        'unit_id' => $labelId,
        'idempotency_key' => 'pod-label-single',
    ]))->assertOk()->assertJsonPath('data.status', 'DELIVERED');

    // El escaneo del POD quedó atado al bulto REAL, no al ULID de la etiqueta.
    $this->assertDatabaseHas('execution_scan_events', [
        'type' => 'PROOF_OF_DELIVERY',
        'unit_id' => $canonicalUnit,
    ]);
    $this->assertDatabaseMissing('execution_scan_events', [
        'unit_id' => $labelId,
    ]);
});

it('el POD con un id que NO resuelve graba el id crudo tal cual (ya es un bulto o es desconocido)', function (): void {
    $shipment = Uuid::generate()->value;
    $rawUnit = Uuid::generate()->value;

    // Mapa vacío: ningún id resuelve, así se simula un unit_id que ya es un shipment_unit.id canónico.
    $this->app->bind(LabelUnitResolver::class, fn () => fakeLabelResolver([]));

    openDeliveryForPod($shipment, $rawUnit);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podLabelBody($shipment, [
        'unit_id' => $rawUnit,
        'idempotency_key' => 'pod-raw-single',
    ]))->assertOk()->assertJsonPath('data.status', 'DELIVERED');

    $this->assertDatabaseHas('execution_scan_events', [
        'type' => 'PROOF_OF_DELIVERY',
        'unit_id' => $rawUnit,
    ]);
});

it('el POD en lote resuelve cada label_id de unit_ids[] a su shipment_unit.id canónico', function (): void {
    $shipment = Uuid::generate()->value;
    $canonicalA = Uuid::generate()->value;
    $canonicalB = Uuid::generate()->value;
    $labelA = '01M73HK0D0C8YB33Z48ADGXX9T';
    $labelB = '01M73HK0D0C8YB33Z48ADGYY0U';

    $this->app->bind(LabelUnitResolver::class, fn () => fakeLabelResolver([
        $labelA => [$canonicalA, $shipment],
        $labelB => [$canonicalB, $shipment],
    ]));

    openDeliveryForPod($shipment, $canonicalA);

    $this->postJson('/api/v1/app/deliveries/proof-of-delivery', podLabelBody($shipment, [
        'unit_ids' => [$labelA, $labelB],
        'idempotency_key' => 'pod-label-batch',
    ]))->assertOk()->assertJsonPath('data.status', 'DELIVERED');

    $this->assertDatabaseHas('execution_scan_events', [
        'type' => 'PROOF_OF_DELIVERY',
        'unit_id' => $canonicalA,
    ]);
    $this->assertDatabaseHas('execution_scan_events', [
        'type' => 'PROOF_OF_DELIVERY',
        'unit_id' => $canonicalB,
    ]);
    $this->assertDatabaseMissing('execution_scan_events', [
        'unit_id' => $labelA,
    ]);
    $this->assertDatabaseMissing('execution_scan_events', [
        'unit_id' => $labelB,
    ]);
});
