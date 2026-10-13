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
        ->and($onRoute(VisibleStatus::Arriving, 0)->stopsMessage)->toBe('Tu pedido es la próxima parada.')
        ->and($onRoute(VisibleStatus::Delivered, 0)->stopsMessage)->toBe('Tu pedido fue entregado.');
});

it('does not leak PII nor internal keys in toArray', function (): void {
    $tracking = trackingForPublicView();
    $array = PublicTrackingView::fromTracking($tracking)->toArray();

    // Solo las claves públicas permitidas.
    expect(array_keys($array))->toBe(['status', 'remaining_stops', 'route_position', 'eta', 'last_position', 'position_is_live', 'stops_message']);

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
        VisibleStatus::Registered,
        0,
        null,
        null,
    );

    $view = PublicTrackingView::fromTracking($tracking);

    expect($view->eta)->toBeNull()
        ->and($view->lastPosition)->toBeNull()
        ->and($view->toArray())->toBe([
            'status' => 'REGISTERED',
            'remaining_stops' => 0,
            'route_position' => null,
            'eta' => null,
            'last_position' => null,
            'position_is_live' => null,
            'stops_message' => 'Recibimos tu pedido y lo estamos preparando.',
        ]);
});
