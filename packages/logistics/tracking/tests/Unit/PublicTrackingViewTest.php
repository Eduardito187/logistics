<?php

declare(strict_types=1);

use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

function trackingForPublicView(): ShipmentTracking
{
    $tracking = ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'd290f1ee-6c54-4b01-90e6-d701748f0851',
        TrackingToken::generate(),
        VisibleStatus::OnTheWay,
        2,
        Eta::at(new DateTimeImmutable('2026-10-13T15:30:00+00:00')),
        TruckPosition::at(
            GeoPoint::of(-16.5, -68.15),
            new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
        ),
    );

    return $tracking;
}

it('exposes only the public-safe fields', function (): void {
    $view = PublicTrackingView::fromTracking(trackingForPublicView());

    expect($view->status)->toBe('ON_THE_WAY')
        ->and($view->remainingStops)->toBe(2)
        ->and($view->eta)->toBe('2026-10-13T15:30:00+00:00')
        ->and($view->stopsMessage)->toBe('Tu pedido está a 2 paradas.')
        ->and($view->lastPosition)->toBe([
            'lat' => -16.5,
            'lng' => -68.15,
            'reported_at' => '2026-10-13T12:00:00+00:00',
        ]);
});

it('phrases the stops message for the customer in Spanish', function (): void {
    $onRoute = fn (VisibleStatus $status, int $stops): PublicTrackingView => PublicTrackingView::fromTracking(
        ShipmentTracking::reconstitute(
            TrackingId::generate(),
            'ship-msg',
            TrackingToken::generate(),
            $status,
            $stops,
            null,
            null,
        ),
    );

    expect($onRoute(VisibleStatus::OnTheWay, 3)->stopsMessage)->toBe('Tu pedido está a 3 paradas.')
        ->and($onRoute(VisibleStatus::OnTheWay, 1)->stopsMessage)->toBe('Tu pedido está a 1 parada.')
        ->and($onRoute(VisibleStatus::OnTheWay, 0)->stopsMessage)->toBe('Tu pedido es la próxima parada.')
        ->and($onRoute(VisibleStatus::Delivered, 0)->stopsMessage)->toBe('Tu pedido fue entregado.');
});

it('does not leak PII nor internal keys in toArray', function (): void {
    $tracking = trackingForPublicView();
    $array = PublicTrackingView::fromTracking($tracking)->toArray();

    // Solo las claves públicas permitidas.
    expect(array_keys($array))->toBe(['status', 'remaining_stops', 'route_position', 'eta', 'last_position', 'position_is_live', 'stops_message', 'at_store_at', 'ready_for_pickup']);

    // Ni id de seguimiento, ni id de envío, ni token.
    expect($array)->not->toHaveKey('id')
        ->and($array)->not->toHaveKey('shipment_id')
        ->and($array)->not->toHaveKey('token');

    // El id de envío y el token no aparecen en ningún valor serializado.
    $flat = json_encode($array);
    expect($flat)->not->toContain($tracking->shipmentId())
        ->and($flat)->not->toContain($tracking->token()->toString());
});

it('serializes null eta and position when absent', function (): void {
    $tracking = ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-7',
        TrackingToken::generate(),
        VisibleStatus::Pending,
        0,
        null,
        null,
    );

    $view = PublicTrackingView::fromTracking($tracking);

    expect($view->eta)->toBeNull()
        ->and($view->lastPosition)->toBeNull()
        ->and($view->toArray())->toBe([
            'status' => 'PENDING',
            'remaining_stops' => 0,
            'route_position' => null,
            'eta' => null,
            'last_position' => null,
            'position_is_live' => null,
            'stops_message' => 'Recibimos tu pedido. Estamos esperando la confirmación del pago.',
            'at_store_at' => null,
            'ready_for_pickup' => false,
        ]);
});

// Llegada a la tienda de retiro: no es un estado nuevo, acompaña al estado y cambia el mensaje al cliente.
function trackingAtStore(VisibleStatus $status, ?DateTimeImmutable $atStoreAt): ShipmentTracking
{
    return ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-store',
        TrackingToken::generate(),
        $status,
        0,
        null,
        null,
        null,
        null,
        'order-store',
        null,
        $atStoreAt,
    );
}

it('marks the arrival at the store once and never after delivery or a terminal status', function (): void {
    $first = new DateTimeImmutable('2026-11-14T11:00:00+00:00');

    $loaded = trackingAtStore(VisibleStatus::Loaded, null);
    expect($loaded->markAtStore($first))->toBeTrue()
        // Reenvío con otra hora: se conserva la primera.
        ->and($loaded->markAtStore(new DateTimeImmutable('2026-11-14T13:00:00+00:00')))->toBeFalse()
        ->and($loaded->atStoreAt())->toEqual($first)
        ->and($loaded->status())->toBe(VisibleStatus::Loaded)
        ->and($loaded->isReadyForPickup())->toBeTrue()
        // No es un hecho nuevo del dominio: no graba eventos.
        ->and($loaded->releaseEvents())->toBe([]);

    foreach ([VisibleStatus::Delivered, VisibleStatus::Cancelled, VisibleStatus::Returned] as $status) {
        $closed = trackingAtStore($status, null);

        expect($closed->markAtStore($first))->toBeFalse()
            ->and($closed->atStoreAt())->toBeNull();
    }
});

it('tells the customer the order is ready for pickup while it waits at the store', function (): void {
    $at = new DateTimeImmutable('2026-11-14T11:00:00+00:00');

    $waiting = PublicTrackingView::fromTracking(trackingAtStore(VisibleStatus::Loaded, $at));
    expect($waiting->readyForPickup)->toBeTrue()
        ->and($waiting->atStoreAt)->toBe('2026-11-14T11:00:00+00:00')
        ->and($waiting->status)->toBe('LOADED')
        ->and($waiting->stopsMessage)->toBe('Tu pedido ya está en la tienda. Puedes pasar a recogerlo.');

    // Ya entregado en el mostrador: conserva la fecha de llegada pero deja de estar «listo para recoger».
    $delivered = PublicTrackingView::fromTracking(trackingAtStore(VisibleStatus::Delivered, $at));
    expect($delivered->readyForPickup)->toBeFalse()
        ->and($delivered->atStoreAt)->toBe('2026-11-14T11:00:00+00:00')
        ->and($delivered->stopsMessage)->toBe('Tu pedido fue entregado.');
});

it('reads a cached snapshot taken before the store fields existed as not at the store', function (): void {
    $legacy = PublicTrackingView::fromArray([
        'status' => 'LOADED',
        'remaining_stops' => 0,
        'route_position' => null,
        'eta' => null,
        'last_position' => null,
        'position_is_live' => null,
        'stops_message' => 'Tu pedido ya está cargado en el camión.',
    ]);

    expect($legacy)->not->toBeNull()
        ->and($legacy?->atStoreAt)->toBeNull()
        ->and($legacy?->readyForPickup)->toBeFalse();

    $current = PublicTrackingView::fromTracking(
        trackingAtStore(VisibleStatus::Loaded, new DateTimeImmutable('2026-11-14T11:00:00+00:00')),
    );

    expect(PublicTrackingView::fromArray($current->toArray())?->toArray())->toBe($current->toArray());
});
