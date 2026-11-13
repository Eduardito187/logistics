<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Event\ShipmentPlacedOnRoute;
use Logistics\Tracking\Domain\Event\TrackingOpened;
use Logistics\Tracking\Domain\Event\TrackingShipmentBound;
use Logistics\Tracking\Domain\Event\TrackingStatusChanged;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

function openTracking(int $remainingStops = 3): ShipmentTracking
{
    return ShipmentTracking::open(
        TrackingId::generate(),
        'order-d290f1ee',
        TrackingToken::generate(),
        $remainingStops,
    );
}

/** Seguimiento reconstituido en un estado dado con un envío ya ligado (para tests de ruta/posición). */
function trackingAt(VisibleStatus $status, int $remainingStops = 3): ShipmentTracking
{
    return ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-1',
        TrackingToken::generate(),
        $status,
        $remainingStops,
        null,
        null,
        null,
        null,
        'order-1',
    );
}

it('opens a tracking in Pending (no shipment) with a token and records TrackingOpened', function (): void {
    $token = TrackingToken::generate();
    $id = TrackingId::generate();

    $tracking = ShipmentTracking::open($id, 'order-123', $token, 4);

    expect($tracking->status())->toBe(VisibleStatus::Pending)
        ->and($tracking->shipmentId())->toBeNull()
        ->and($tracking->orderReference())->toBe('order-123')
        ->and($tracking->token()->equals($token))->toBeTrue()
        ->and($tracking->remainingStops())->toBe(4);

    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TrackingOpened::class)
        ->and($events[0]->payload())->toBe([
            'tracking_id' => $id->toString(),
            'shipment_id' => null,
            'order_reference' => 'order-123',
            'token' => $token->toString(),
            'status' => 'PENDING',
            'remaining_stops' => 4,
        ]);
});

it('rejects a blank order reference', function (): void {
    expect(fn () => ShipmentTracking::open(
        TrackingId::generate(),
        '   ',
        TrackingToken::generate(),
        0,
    ))->toThrow(TrackingException::class, 'orden');
});

it('rejects negative remaining stops', function (): void {
    expect(fn () => ShipmentTracking::open(
        TrackingId::generate(),
        'order-1',
        TrackingToken::generate(),
        -1,
    ))->toThrow(TrackingException::class, 'negativas');
});

it('binds a shipment to the order tracking and records TrackingShipmentBound', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $tracking->bindShipment('ship-abc');

    expect($tracking->shipmentId())->toBe('ship-abc');
    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TrackingShipmentBound::class)
        ->and($events[0]->payload()['shipment_id'])->toBe('ship-abc');

    // Re-atar el mismo envío es idempotente (no graba otro hecho).
    $tracking->bindShipment('ship-abc');
    expect($tracking->releaseEvents())->toHaveCount(0);
});

it('applies an allowed transition and records TrackingStatusChanged', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $tracking->applyStatus(VisibleStatus::Processing);

    expect($tracking->status())->toBe(VisibleStatus::Processing);
    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TrackingStatusChanged::class)
        ->and($events[0]->payload()['previous_status'])->toBe('PENDING')
        ->and($events[0]->payload()['status'])->toBe('PROCESSING');
});

it('ignores applying the same status: no event', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $tracking->applyStatus(VisibleStatus::Pending);

    expect($tracking->status())->toBe(VisibleStatus::Pending)
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('rejects a jump that skips the flow (no illegal transitions)', function (): void {
    $tracking = openTracking();

    expect(fn () => $tracking->applyStatus(VisibleStatus::Delivered))
        ->toThrow(TrackingException::class, 'no admitida');
});

// Regresión del POD que dejaba el envío atascado en «Cargada»: el app entrega con el POD aunque nunca se
// haya registrado la salida del camión (OnTheWay lo emite el inicio de ruta, que puede faltar). La entrega
// debe admitirse directo desde Cargado — antes se descartaba y el envío no avanzaba a Entregado.
it('allows delivery directly from Loaded (POD without a departed event)', function (): void {
    $tracking = openTracking();
    $tracking->markProcessing();
    $tracking->markInProgress();
    $tracking->markLoaded();
    $tracking->releaseEvents();

    $tracking->markDelivered(new DateTimeImmutable('2026-11-13T12:00:00+00:00'));

    expect($tracking->status())->toBe(VisibleStatus::Delivered)
        ->and($tracking->releaseEvents())->toHaveCount(1);
});

// POD directo desde Preparado (el app ya no manda escaneo de carga): igual que el agregado Delivery de
// execution, el seguimiento debe poder entregar sin pasar por Cargado.
it('allows delivery directly from InProgress (POD without a load scan)', function (): void {
    $tracking = openTracking();
    $tracking->markProcessing();
    $tracking->markInProgress();
    $tracking->releaseEvents();

    $tracking->markDelivered();

    expect($tracking->status())->toBe(VisibleStatus::Delivered);
});

it('allows a failed attempt directly from Loaded', function (): void {
    $tracking = openTracking();
    $tracking->markProcessing();
    $tracking->markInProgress();
    $tracking->markLoaded();
    $tracking->releaseEvents();

    $tracking->markFailed();

    expect($tracking->status())->toBe(VisibleStatus::Failed);
});

it('does not allow leaving a terminal status', function (): void {
    $tracking = openTracking();
    $tracking->cancel(); // pendiente → cancelada (terminal)
    $tracking->releaseEvents();

    expect(fn () => $tracking->applyStatus(VisibleStatus::Processing))
        ->toThrow(TrackingException::class, 'no admitida');
});

it('walks the full happy path and allows return after delivery', function (): void {
    $tracking = openTracking();
    $tracking->markProcessing();
    $tracking->markInProgress();
    $tracking->markLoaded();
    $tracking->markOnTheWay();
    $tracking->markDelivered();
    expect($tracking->status())->toBe(VisibleStatus::Delivered);

    $tracking->markReturned();
    expect($tracking->status())->toBe(VisibleStatus::Returned)
        ->and($tracking->status()->isTerminal())->toBeTrue();
});

it('updates the truck position without recording an event', function (): void {
    $tracking = trackingAt(VisibleStatus::OnTheWay);

    $position = TruckPosition::at(
        GeoPoint::of(-16.5, -68.15),
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    );
    $tracking->updatePosition($position);

    expect($tracking->lastPosition()?->equals($position))->toBeTrue()
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('sets the ETA without recording an event', function (): void {
    $tracking = trackingAt(VisibleStatus::OnTheWay);

    $eta = Eta::at(new DateTimeImmutable('2026-10-13T15:30:00+00:00'));
    $tracking->setEta($eta);

    expect($tracking->eta()?->equals($eta))->toBeTrue()
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('decrements remaining stops and never goes below zero', function (): void {
    $tracking = trackingAt(VisibleStatus::OnTheWay, 2);

    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(1);

    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(0);

    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(0)
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('places the shipment on a route, derives stops before the customer and records the fact', function (): void {
    $tracking = trackingAt(VisibleStatus::OnTheWay);

    $tracking->placeOnRoute('route-7', RoutePosition::of(3));

    expect($tracking->routeId())->toBe('route-7')
        ->and($tracking->routePosition()?->value)->toBe(3)
        ->and($tracking->remainingStops())->toBe(2);

    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentPlacedOnRoute::class)
        ->and($events[0]->payload())->toMatchArray([
            'route_id' => 'route-7',
            'route_position' => 3,
            'stops_remaining' => 2,
        ]);
});

it('rejects a blank route id when placing on a route', function (): void {
    $tracking = trackingAt(VisibleStatus::OnTheWay);

    expect(fn () => $tracking->placeOnRoute('  ', RoutePosition::of(1)))
        ->toThrow(TrackingException::class, 'ruta');
});

it('does not re-place a terminal tracking on a route', function (): void {
    $tracking = trackingAt(VisibleStatus::Returned);

    $tracking->placeOnRoute('route-7', RoutePosition::of(5));

    expect($tracking->routeId())->toBeNull()
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('rehydrates from state without recording events', function (): void {
    $tracking = ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-9',
        TrackingToken::generate(),
        VisibleStatus::Processing,
        1,
        null,
        null,
    );

    expect($tracking->status())->toBe(VisibleStatus::Processing)
        ->and($tracking->pendingEvents())->toHaveCount(0);
});
