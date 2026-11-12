<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * POD en lote (#14), GPS opcional del POD (#25) y entrega del transportista sin QR del cliente.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const PODB_AT = '2026-11-12T11:00:00-04:00';

/**
 * Prepara y carga los bultos dados para dejar la entrega lista para el POD.
 *
 * @param list<string> $units
 */
function podbLoaded(string $shipment, array $units): void
{
    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $vehicle = Uuid::generate()->value;

    foreach ($units as $unit) {
        $bus->dispatch(new RecordPreparationScan($shipment, $vehicle, $unit, -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:00:00-04:00', 'podb-prep-'.$shipment.$unit));
        $bus->dispatch(new RecordLoadScan($shipment, $unit, -16.5, -68.15, 'dev', 'usr', '2026-11-12T08:30:00-04:00', 'podb-load-'.$shipment.$unit));
    }
}

it('cierra la entrega con varios bultos en un solo POD y vincula las evidencias', function (): void {
    $shipment = Uuid::generate()->value;
    podbLoaded($shipment, ['U-1', 'U-2']);
    $evidence = Uuid::generate()->value;

    $body = [
        'shipment_id' => $shipment,
        'unit_ids' => ['U-1', 'U-2'],
        'confirmation' => 'FIRMA_DIGITAL',
        'photo_paths' => ['pod/a.jpg', 'pod/b.jpg'],
        'evidence_ids' => [$evidence],
        'occurred_at' => PODB_AT,
        'idempotency_key' => 'podb-batch-1',
    ];

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', $body)
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.units_delivered', 2)
        ->assertJsonPath('data.pod_photo_path', 'pod/a.jpg');

    // Reintento idempotente: no duplica escaneos.
    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', $body)->assertOk();

    $row = DB::table('execution_deliveries')->where('shipment_id', $shipment)->first(['pod_photo_paths', 'pod_evidence_ids']);
    expect(json_decode((string) $row?->pod_photo_paths, true))->toBe(['pod/a.jpg', 'pod/b.jpg'])
        ->and(json_decode((string) $row?->pod_evidence_ids, true))->toBe([$evidence])
        ->and(DB::table('execution_scan_events')->where('type', 'PROOF_OF_DELIVERY')->where('idempotency_key', 'like', 'podb-batch-1%')->count())->toBe(2);
});

it('acepta el POD por envío en /complete y sin GPS no guarda 0,0', function (): void {
    $shipment = Uuid::generate()->value;
    podbLoaded($shipment, ['U-1']);

    test()->postJson("/api/v1/app/deliveries/{$shipment}/complete", [
        'unit_id' => 'U-1',
        'confirmation' => 'FIRMA_DIGITAL',
        'photo_path' => 'pod/c.jpg',
        'lat' => 0,
        'lng' => 0,
        'occurred_at' => PODB_AT,
        'idempotency_key' => 'podb-complete-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED');

    $scan = DB::table('execution_scan_events')->where('idempotency_key', 'podb-complete-1')->first(['lat', 'lng']);
    expect($scan?->lat)->toBeNull()->and($scan?->lng)->toBeNull();
});

it('guarda la ubicación con precisión cuando viene location', function (): void {
    $shipment = Uuid::generate()->value;
    podbLoaded($shipment, ['U-1']);

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', [
        'shipment_id' => $shipment,
        'unit_id' => 'U-1',
        'confirmation' => 'FIRMA_DIGITAL',
        'photo_path' => 'pod/d.jpg',
        'location' => ['lat' => -16.52, 'lng' => -68.11, 'accuracy_m' => 12.5, 'captured_at' => '2026-11-12T10:59:00-04:00'],
        'occurred_at' => PODB_AT,
        'idempotency_key' => 'podb-loc-1',
    ])->assertOk();

    $scan = DB::table('execution_scan_events')->where('idempotency_key', 'podb-loc-1')->first(['lat', 'accuracy_m', 'location_captured_at']);
    expect((float) $scan?->lat)->toBe(-16.52)
        ->and((float) $scan?->accuracy_m)->toBe(12.5)
        ->and($scan?->location_captured_at)->not->toBeNull();
});

it('el transportista entrega sin QR del cliente; el resto sigue exigiendo confirmación', function (): void {
    $shipment = Uuid::generate()->value;
    podbLoaded($shipment, ['U-1']);
    $body = [
        'shipment_id' => $shipment,
        'unit_id' => 'U-1',
        'photo_path' => 'pod/e.jpg',
        'occurred_at' => PODB_AT,
        'idempotency_key' => 'podb-carrier-1',
    ];

    // Chofer sin confirmación → 422.
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    test()->actingAs($driver, 'sanctum')
        ->postJson('/api/v1/app/deliveries/proof-of-delivery', $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    // Transportista sin confirmación → entregado con el marcador CARRIER_HANDOVER.
    $carrier = User::factory()->create();
    $carrier->assignRole(Role::Transportista);
    test()->actingAs($carrier, 'sanctum')
        ->postJson('/api/v1/app/deliveries/proof-of-delivery', $body)
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.pod_confirmation', 'CARRIER_HANDOVER');
});

it('exige la foto también en el POD en lote', function (): void {
    $shipment = Uuid::generate()->value;
    podbLoaded($shipment, ['U-1']);

    test()->postJson('/api/v1/app/deliveries/proof-of-delivery', [
        'shipment_id' => $shipment,
        'unit_ids' => ['U-1'],
        'confirmation' => 'FIRMA_DIGITAL',
        'occurred_at' => PODB_AT,
        'idempotency_key' => 'podb-nophoto-1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});
