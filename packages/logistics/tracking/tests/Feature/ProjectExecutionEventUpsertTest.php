<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// UPSERT de la proyección: un envío DELIVERED en execution SIN fila en tracking (outbox congelado o entrada
// fuera de flujo) igual debe recibir su seguimiento. El handler lo abre desde el order_reference del envío,
// lo liga y lo deja entregado. Sin referencia de orden, es un no-op tolerante. Corre contra logistics_test.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function upsertBus(): CommandBus
{
    return app(CommandBus::class);
}

function upsertTrackings(): TrackingRepository
{
    return app(TrackingRepository::class);
}

it('opens and delivers a tracking for a shipment that had no tracking row, from its order_reference', function (): void {
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'ORD-UPSERT-1',
        'order_reference' => 'ORD-UPSERT-1',
    ]);

    // No hay seguimiento previo para este envío.
    expect(upsertTrackings()->findByShipmentId($shipment->id))->toBeNull();

    upsertBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    $tracking = upsertTrackings()->findByShipmentId($shipment->id);
    expect($tracking)->not->toBeNull()
        ->and($tracking->status())->toBe(VisibleStatus::Delivered)
        ->and($tracking->orderReference())->toBe('ORD-UPSERT-1')
        ->and($tracking->deliveredAt())->not->toBeNull();
});

it('falls back to external_id (stripping the #n: suffix) when order_reference is empty', function (): void {
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'ORD-UPSERT-2#n:node-9',
        'order_reference' => null,
    ]);

    upsertBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    $tracking = upsertTrackings()->findByShipmentId($shipment->id);
    expect($tracking)->not->toBeNull()
        ->and($tracking->status())->toBe(VisibleStatus::Delivered)
        ->and($tracking->orderReference())->toBe('ORD-UPSERT-2');
});

it('is a tolerant no-op when the shipment has no reference (no shipments row)', function (): void {
    $shipmentId = '0a1a1c71-8152-787d-ba31-c8ef41082500';

    upsertBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipmentId));

    expect(upsertTrackings()->findByShipmentId($shipmentId))->toBeNull();
});

it('does not duplicate when the same event is projected twice (idempotent upsert)', function (): void {
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'ORD-UPSERT-3',
        'order_reference' => 'ORD-UPSERT-3',
    ]);

    upsertBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));
    upsertBus()->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment->id));

    $all = upsertTrackings()->findAllByOrderReference('ORD-UPSERT-3');
    expect($all)->toHaveCount(1)
        ->and($all[0]->status())->toBe(VisibleStatus::Delivered);
});
