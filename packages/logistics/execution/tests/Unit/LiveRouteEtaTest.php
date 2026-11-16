<?php

declare(strict_types=1);

use Logistics\Execution\Application\ReadModel\RouteStopView;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Infrastructure\ReadModel\QueryBusLiveRouteStateReader;
use Logistics\Execution\Presentation\Broadcasting\RouteUpdated;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

// Motor único de ETA en la hoja de ruta: la ETA viva se superpone sobre la planificada, el estado "fuera de
// ruta" y la ETA viajan en `route.updated`, y el lector cross-context normaliza o degrada sin romper.

const LRE_PLANNED_ETA = '2026-11-16T15:00:00+00:00';

const LRE_LIVE_ETA = '2026-11-16T15:20:00+00:00';

const LRE_SHIPMENT = 'ship-0001';

function lreStop(): RouteStop
{
    return RouteStop::plan(
        RouteStopId::generate(),
        LRE_SHIPMENT,
        1,
        'ORD-1',
        'Cliente',
        'Calle 1',
        new DateTimeImmutable(LRE_PLANNED_ETA),
        -16.5,
        -68.15,
    );
}

it('la ETA viva se superpone sobre la planificada; sin ETA viva queda la planificada', function (): void {
    $withLive = RouteStopView::fromStop(lreStop(), liveEtaAt: LRE_LIVE_ETA);
    $withoutLive = RouteStopView::fromStop(lreStop());

    expect($withLive->toArray()['eta_at'])->toBe(LRE_LIVE_ETA)
        ->and($withoutLive->toArray()['eta_at'])->toBe(LRE_PLANNED_ETA);
});

it('route.updated incluye off_route/rerouted/stops solo cuando viajan, manteniendo compatibilidad', function (): void {
    $plain = new RouteUpdated([1], 'route-1', 'veh-1');
    $full = new RouteUpdated([1], 'route-1', 'veh-1', true, true, [
        ['shipment_id' => LRE_SHIPMENT, 'eta_at' => LRE_LIVE_ETA],
    ]);

    expect($plain->broadcastWith())->toBe(['route_id' => 'route-1', 'vehicle_id' => 'veh-1'])
        ->and($full->broadcastWith())->toBe([
            'route_id' => 'route-1',
            'vehicle_id' => 'veh-1',
            'off_route' => true,
            'rerouted' => true,
            'stops' => [['shipment_id' => LRE_SHIPMENT, 'eta_at' => LRE_LIVE_ETA]],
        ]);
});

it('el lector cross-context normaliza la vista del motor de ETA a mapa envío→ETA + estado de desvío', function (): void {
    $view = new readonly class
    {
        /** @return array<string, mixed> */
        public function toArray(): array
        {
            return [
                'off_route' => true,
                'off_route_since' => '2026-11-16T15:05:00+00:00',
                'position' => ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-11-16T15:10:00+00:00'],
                'position_stale' => false,
                'stops' => [
                    ['shipment_id' => LRE_SHIPMENT, 'eta' => LRE_LIVE_ETA],
                    ['shipment_id' => 'ship-0002', 'eta' => null],
                ],
            ];
        }
    };

    $reader = new QueryBusLiveRouteStateReader(new class($view) implements QueryBus
    {
        public function __construct(private object $view) {}

        public function ask(Query $query): mixed
        {
            return $this->view;
        }
    });

    $state = $reader->forVehicle('veh-1');

    expect($state)->not->toBeNull()
        ->and($state['off_route'])->toBeTrue()
        ->and($state['off_route_since'])->toBe('2026-11-16T15:05:00+00:00')
        ->and($state['etas'])->toBe([LRE_SHIPMENT => LRE_LIVE_ETA]);
});

it('el lector degrada a null si el motor de ETA falla o el vehículo viene vacío', function (): void {
    $throwing = new QueryBusLiveRouteStateReader(new class implements QueryBus
    {
        public function ask(Query $query): mixed
        {
            throw new RuntimeException('motor caído');
        }
    });

    expect($throwing->forVehicle('veh-1'))->toBeNull()
        ->and($throwing->forVehicle('  '))->toBeNull();
});
