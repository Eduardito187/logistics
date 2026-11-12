<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Valores repetidos en los escenarios de conciliación (endpoint, instante y nota del informe).
const RECON_REPORT_TEST_URL = '/api/v1/app/node-receptions/reconcile';
const RECON_REPORT_TEST_AT = '2026-11-12T10:00:00+00:00';
const RECON_REPORT_TEST_NOTES = 'Llegó una caja abierta';

/**
 * #11 — Conciliar con motivo y evidencia: `missing:[{unit_id, reason}]`, `surplus:[{unit_id,
 * belongs_to_shipment_id?}]`, `notes`, `photo_path`; se persiste y se devuelve en `reconciliation`. El cuerpo
 * histórico (sin informe) sigue aceptado. Y #25: recibir sin GPS (null / objeto `location`).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @return array{shipment: string, node: string}
 */
function reportOpenReception(): array
{
    $shipment = Uuid::generate()->value;
    $node = Uuid::generate()->value;

    app(CommandBus::class)->dispatch(new OpenNodeReception(
        shipmentId: $shipment,
        originNodeId: Uuid::generate()->value,
        destinationNodeId: $node,
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: ['U-1', 'U-2', 'U-3'],
        occurredAt: '2026-11-12T08:00:00+00:00',
    ));

    return ['shipment' => $shipment, 'node' => $node];
}

/**
 * @param  array<string, mixed> $extra
 * @return array<string, mixed>
 */
function reportReceiveBody(string $shipment, string $node, string $unit, array $extra = []): array
{
    return array_merge([
        'shipment_id' => $shipment,
        'destination_node_id' => $node,
        'unit_id' => $unit,
        'occurred_at' => '2026-11-12T09:00:00+00:00',
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'idempotency_key' => 'rcv-'.$shipment.'-'.$unit,
        'lat' => -16.5,
        'lng' => -68.15,
    ], $extra);
}

it('reconciles with reasons, surplus owner, notes and photo and returns them', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();
    $this->postJson('/api/v1/app/node-receptions/units', reportReceiveBody($shipment, $node, 'U-1'))->assertOk();
    $this->postJson('/api/v1/app/node-receptions/units', reportReceiveBody($shipment, $node, 'U-9'))->assertOk();
    $owner = Uuid::generate()->value;

    $response = $this->postJson(RECON_REPORT_TEST_URL, [
        'shipment_id' => $shipment,
        'destination_node_id' => $node,
        'occurred_at' => RECON_REPORT_TEST_AT,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'notes' => RECON_REPORT_TEST_NOTES,
        'photo_path' => 'evidence/reception-42.jpg',
        'missing' => [
            ['unit_id' => 'U-2', 'reason' => 'damaged'],
            ['unit_id' => 'U-3', 'reason' => 'not_arrived'],
        ],
        'surplus' => [['unit_id' => 'U-9', 'belongs_to_shipment_id' => $owner]],
    ])->assertOk();

    expect($response->json('data.status'))->toBe('RECONCILED')
        ->and($response->json('data.missing_count'))->toBe(2)
        ->and($response->json('data.surplus_count'))->toBe(1)
        ->and($response->json('data.reconciliation'))->toBe([
            'notes' => RECON_REPORT_TEST_NOTES,
            'photo_path' => 'evidence/reception-42.jpg',
            'missing' => [
                ['unit_id' => 'U-2', 'reason' => 'damaged'],
                ['unit_id' => 'U-3', 'reason' => 'not_arrived'],
            ],
            'surplus' => [['unit_id' => 'U-9', 'belongs_to_shipment_id' => $owner]],
        ]);

    // Persistido: un GET posterior lo devuelve igual.
    $this->getJson("/api/v1/app/node-receptions/{$shipment}/{$node}")
        ->assertOk()
        ->assertJsonPath('data.reconciliation.missing.0.reason', 'damaged');

    $payload = OutboxMessageRecord::query()
        ->where('event_name', 'execution.node_reception_reconciled')
        ->where('aggregate_id', NodeReceptionRecord::query()->where('shipment_id', $shipment)->value('id'))
        ->firstOrFail()
        ->payload;
    expect($payload['missing_units'])->toHaveCount(2)
        ->and($payload['notes'])->toBe(RECON_REPORT_TEST_NOTES);
});

it('keeps accepting the legacy reconcile body and leaves reasons null', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();

    $this->postJson(RECON_REPORT_TEST_URL, [
        'shipment_id' => $shipment,
        'destination_node_id' => $node,
        'occurred_at' => RECON_REPORT_TEST_AT,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
    ])->assertOk()
        ->assertJsonPath('data.reconciliation.notes', null)
        ->assertJsonPath('data.reconciliation.missing.0.unit_id', 'U-1')
        ->assertJsonPath('data.reconciliation.missing.0.reason', null);
});

it('returns reconciliation null while the reception is open', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();

    $this->getJson("/api/v1/app/node-receptions/{$shipment}/{$node}")
        ->assertOk()
        ->assertJsonPath('data.reconciliation', null)
        ->assertJsonPath('data.missing_count', 3);
});

it('rejects an unknown missing reason with 422', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();

    $this->postJson(RECON_REPORT_TEST_URL, [
        'shipment_id' => $shipment,
        'destination_node_id' => $node,
        'occurred_at' => RECON_REPORT_TEST_AT,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
        'missing' => [['unit_id' => 'U-1', 'reason' => 'stolen_by_aliens']],
    ])->assertStatus(422);
});

it('receives a unit without GPS (null lat/lng) and stores no position', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();

    $this->postJson('/api/v1/app/node-receptions/units', reportReceiveBody($shipment, $node, 'U-1', ['lat' => null, 'lng' => null]))
        ->assertOk()
        ->assertJsonPath('data.received_count', 1);

    $row = DB::table('execution_node_reception_units')->where('unit_id', 'U-1')->first();
    expect($row->lat)->toBeNull()->and($row->lng)->toBeNull();
});

it('treats 0,0 as no GPS instead of a real position', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();

    $this->postJson('/api/v1/app/node-receptions/units', reportReceiveBody($shipment, $node, 'U-2', ['lat' => 0, 'lng' => 0]))
        ->assertOk();

    expect(DB::table('execution_node_reception_units')->where('unit_id', 'U-2')->value('lat'))->toBeNull();
});

it('accepts the optional location object with accuracy and capture time', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();
    $body = reportReceiveBody($shipment, $node, 'U-3');
    unset($body['lat'], $body['lng']);
    $body['location'] = ['lat' => -17.78, 'lng' => -63.18, 'accuracy_m' => 8.5, 'captured_at' => '2026-11-12T08:59:30+00:00'];

    $this->postJson('/api/v1/app/node-receptions/units', $body)->assertOk();

    $row = DB::table('execution_node_reception_units')->where('unit_id', 'U-3')->first();
    expect((float) $row->lat)->toBe(-17.78)
        ->and((float) $row->accuracy_m)->toBe(8.5)
        ->and($row->location_captured_at)->not->toBeNull();
});

it('rejects lat without lng', function (): void {
    ['shipment' => $shipment, 'node' => $node] = reportOpenReception();
    $body = reportReceiveBody($shipment, $node, 'U-1');
    unset($body['lng']);

    $this->postJson('/api/v1/app/node-receptions/units', $body)->assertStatus(422);
});
