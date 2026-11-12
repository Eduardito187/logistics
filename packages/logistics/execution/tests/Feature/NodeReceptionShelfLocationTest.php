<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionUnitLocationRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\UnitTrackingRecord;

/**
 * #7 — Ubicación en estante de los productos recibidos: un producto o un lote, idempotente, persistida y
 * devuelta en la vista de la recepción (`units[].location`) y en el detalle por bulto de tracking.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function shelfNode(): string
{
    return 'b7777777-7777-7777-8777-777777777777';
}

/**
 * Abre la recepción de un envío con dos productos físicos reales.
 *
 * @return array{shipment: string, units: list<string>}
 */
function shelfOpenReception(): array
{
    $shipment = ShipmentRecord::factory()->create();
    $line = ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id, 'sku' => 'SKU-TV55', 'product_name' => 'Televisor 55"']);
    $units = [];
    foreach ([1, 2] as $index) {
        $units[] = PhysicalUnitRecord::factory()->create([
            'shipment_id' => $shipment->id,
            'line_id' => $line->id,
            'unit_index' => $index,
            'unit_total' => 2,
            'piece_index' => 1,
            'piece_total' => 1,
        ])->id;
    }

    app(CommandBus::class)->dispatch(new OpenNodeReception(
        shipmentId: $shipment->id,
        originNodeId: 'a7777777-7777-7777-8777-777777777777',
        destinationNodeId: shelfNode(),
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: $units,
        occurredAt: '2026-11-12T08:00:00+00:00',
    ));

    return ['shipment' => $shipment->id, 'units' => $units];
}

/**
 * @return array<string, mixed>
 */
function shelfBody(string $location, string $at = '2026-11-12T09:00:00+00:00'): array
{
    return [
        'node_id' => shelfNode(),
        'location' => $location,
        'occurred_at' => $at,
        'device_id' => 'device-01',
        'user_id' => 'user-07',
    ];
}

it('shelves one unit and returns the location in the reception view', function (): void {
    ['shipment' => $shipment, 'units' => [$u1, $u2]] = shelfOpenReception();

    $response = $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/{$u1}/location", shelfBody('A-03-2'))
        ->assertOk();

    $units = collect($response->json('data.units'))->keyBy('unit_id');
    expect($units[$u1]['location'])->toBe('A-03-2')
        ->and($units[$u1]['located_at'])->not->toBeNull()
        ->and($units[$u1]['product_name'])->toBe('Televisor 55"')
        ->and($units[$u1]['sku'])->toBe('SKU-TV55')
        ->and($units[$u2]['location'])->toBeNull();

    expect(OutboxMessageRecord::query()->where('event_name', 'execution.node_unit_shelved')->count())->toBe(1);
});

it('is idempotent: re-sending the same location writes nothing new', function (): void {
    ['shipment' => $shipment, 'units' => [$u1]] = shelfOpenReception();

    $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/{$u1}/location", shelfBody('A-03-2'))->assertOk();
    $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/{$u1}/location", shelfBody('A-03-2'))->assertOk();

    expect(NodeReceptionUnitLocationRecord::query()->where('unit_id', $u1)->count())->toBe(1)
        ->and(OutboxMessageRecord::query()->where('event_name', 'execution.node_unit_shelved')->count())->toBe(1);
});

it('shelves a batch of units with the same location', function (): void {
    ['shipment' => $shipment, 'units' => $units] = shelfOpenReception();

    $response = $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/location", shelfBody('B-01') + ['unit_ids' => $units])
        ->assertOk();

    expect(collect($response->json('data.units'))->pluck('location')->unique()->values()->all())->toBe(['B-01'])
        ->and(NodeReceptionUnitLocationRecord::query()->whereIn('unit_id', $units)->count())->toBe(2);
});

it('relocates with a newer time and ignores an older one', function (): void {
    ['shipment' => $shipment, 'units' => [$u1]] = shelfOpenReception();
    $url = "/api/v1/app/node-receptions/{$shipment}/units/{$u1}/location";

    $this->postJson($url, shelfBody('A-01', '2026-11-12T09:00:00+00:00'))->assertOk();
    $this->postJson($url, shelfBody('C-09', '2026-11-12T10:00:00+00:00'))->assertOk();
    $this->postJson($url, shelfBody('Z-99', '2026-11-12T08:00:00+00:00'))->assertOk(); // reenvío tardío

    expect(NodeReceptionUnitLocationRecord::query()->where('unit_id', $u1)->value('location'))->toBe('C-09');
});

it('answers 422 when a unit does not belong to the reception (whole batch rejected)', function (): void {
    ['shipment' => $shipment, 'units' => [$u1]] = shelfOpenReception();

    $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/location", shelfBody('B-01') + ['unit_ids' => [$u1, 'U-404']])
        ->assertStatus(422);

    expect(NodeReceptionUnitLocationRecord::query()->where('unit_id', $u1)->exists())->toBeFalse();
});

it('validates the batch form requires unit_ids and a location', function (): void {
    ['shipment' => $shipment] = shelfOpenReception();

    $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/location", shelfBody(''))
        ->assertStatus(422);
});

it('exposes the shelf location in the tracking per-unit detail', function (): void {
    ['shipment' => $shipment, 'units' => [$u1, $u2]] = shelfOpenReception();
    foreach ([$u1, $u2] as $unit) {
        UnitTrackingRecord::factory()->create([
            'shipment_id' => $shipment,
            'unit_id' => $unit,
            'status' => UnitStatus::Received->value,
        ]);
    }

    $this->postJson("/api/v1/app/node-receptions/{$shipment}/units/{$u1}/location", shelfBody('A-03-2'))->assertOk();

    $units = collect($this->getJson("/api/v1/app/tracking/shipments/{$shipment}/units")
        ->assertOk()
        ->json('data.units'))->keyBy('unit_id');

    expect($units[$u1]['location'])->toBe('A-03-2')
        ->and($units[$u2]['location'])->toBeNull();
});
