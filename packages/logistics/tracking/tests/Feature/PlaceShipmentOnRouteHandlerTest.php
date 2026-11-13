<?php

declare(strict_types=1);

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRoute;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRouteHandler;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Handler puente que proyecta la posición de un envío en la ruta publicada sobre su seguimiento. Tolerante:
 * si no hay seguimiento, no hace nada. Deriva las paradas restantes de la posición. Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function psorBus(): CommandBus
{
    return app(CommandBus::class);
}

it('ubica el envío en la ruta y deriva las paradas restantes', function (): void {
    $shipment = Uuid::generate()->value;
    $route = Uuid::generate()->value;
    TrackingRecord::factory()->withStatus(VisibleStatus::InProgress)->create([
        'shipment_id' => $shipment,
        'route_id' => null,
        'route_position' => null,
        'remaining_stops' => 0,
    ]);

    psorBus()->dispatch(new PlaceShipmentOnRoute(
        shipmentId: $shipment,
        routeId: $route,
        routePosition: 3,
    ));

    $record = TrackingRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($record->route_id)->toBe($route)
        ->and($record->route_position)->toBe(3)
        ->and($record->remaining_stops)->toBe(2);
});

it('no hace nada si todavía no se abrió el seguimiento del envío', function (): void {
    $shipment = Uuid::generate()->value;

    psorBus()->dispatch(new PlaceShipmentOnRoute(
        shipmentId: $shipment,
        routeId: Uuid::generate()->value,
        routePosition: 1,
    ));

    expect(TrackingRecord::query()->where('shipment_id', $shipment)->exists())->toBeFalse();
});

it('rechaza un comando que no es PlaceShipmentOnRoute', function (): void {
    $other = new class implements Command {};

    expect(fn () => app(PlaceShipmentOnRouteHandler::class)->handle($other))
        ->toThrow(TrackingException::class, 'PlaceShipmentOnRoute');
});
