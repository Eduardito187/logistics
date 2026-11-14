<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\SyncRouteStops\SyncRouteStops;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// El seguimiento de un envío VIVO debe existir y decir cuántas paradas le faltan apenas el camión lo tiene
// en su ruta, no recién al entregarse. SyncRouteStops proyecta la ruta real (execution_driver_route_stops)
// sobre el seguimiento: abre el que falte, fija la posición y cuenta las paradas pendientes por delante.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma una ruta con una parada por envío, en el orden dado. Devuelve [routeId, shipmentIds].
 *
 * @param  list<string>                      $statuses estado de cada parada, en orden de visita
 * @return array{0: string, 1: list<string>}
 */
function srsRoute(array $statuses): array
{
    $routeId = (string) Str::uuid7();
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId,
        'vehicle_id' => (string) Str::uuid7(),
        'route_date' => '2026-11-14',
        'status' => 'IN_PROGRESS',
    ]);

    $shipments = [];
    foreach ($statuses as $i => $status) {
        $shipment = ShipmentRecord::factory()->create([
            'external_id' => 'ORD-SRS-'.Str::random(8),
            'order_reference' => 'ORD-SRS-'.Str::random(8),
        ]);
        $shipments[] = (string) $shipment->id;

        DB::table('execution_driver_route_stops')->insert([
            'id' => (string) Str::uuid7(),
            'route_id' => $routeId,
            'shipment_id' => $shipment->id,
            'sequence' => $i + 1,
            'status' => $status,
        ]);
    }

    return [$routeId, $shipments];
}

function srsTrackings(): TrackingRepository
{
    return app(TrackingRepository::class);
}

it('abre el seguimiento que falta y cuenta las paradas pendientes por delante de cada envío', function (): void {
    [$routeId, $shipments] = srsRoute(['PENDING', 'PENDING', 'PENDING']);

    expect(srsTrackings()->findByShipmentId($shipments[2]))->toBeNull();

    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId));

    foreach ([0, 1, 2] as $i) {
        $tracking = srsTrackings()->findByShipmentId($shipments[$i]);

        expect($tracking)->not->toBeNull()
            ->and($tracking->status())->toBe(VisibleStatus::Processing)
            ->and($tracking->routeId())->toBe($routeId)
            ->and($tracking->routePosition()?->value)->toBe($i + 1)
            ->and($tracking->remainingStops())->toBe($i);
    }
});

it('no cuenta las paradas ya resueltas de una ruta en curso', function (): void {
    [$routeId, $shipments] = srsRoute(['COMPLETED', 'FAILED', 'PENDING', 'PENDING']);

    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId));

    // Tercera parada: las dos anteriores ya se resolvieron, es la próxima.
    expect(srsTrackings()->findByShipmentId($shipments[2])->remainingStops())->toBe(0)
        ->and(srsTrackings()->findByShipmentId($shipments[2])->routePosition()?->value)->toBe(3)
        ->and(srsTrackings()->findByShipmentId($shipments[3])->remainingStops())->toBe(1)
        // Las resueltas no se abren desde acá: su seguimiento lo cierra el hecho de entrega/fallo.
        ->and(srsTrackings()->findByShipmentId($shipments[0]))->toBeNull();
});

it('reproyectar deja el mismo resultado y el modo silencioso no graba eventos', function (): void {
    [$routeId, $shipments] = srsRoute(['PENDING', 'PENDING']);
    $before = DB::table('outbox_messages')->count();

    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId, null, true));
    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId, null, true));

    expect(DB::table('outbox_messages')->count())->toBe($before)
        ->and(DB::table('tracking_shipments')->whereIn('shipment_id', $shipments)->count())->toBe(2)
        ->and(srsTrackings()->findByShipmentId($shipments[1])->remainingStops())->toBe(1);
});

it('al entregarse una parada, las de atrás quedan una parada más cerca', function (): void {
    [$routeId, $shipments] = srsRoute(['PENDING', 'PENDING', 'PENDING']);
    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId));

    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipments[0]));

    expect(srsTrackings()->findByShipmentId($shipments[0])->status())->toBe(VisibleStatus::Delivered)
        ->and(srsTrackings()->findByShipmentId($shipments[1])->remainingStops())->toBe(0)
        ->and(srsTrackings()->findByShipmentId($shipments[2])->remainingStops())->toBe(1);
});

it('el envío que sale de la ruta deja de mostrar sus paradas y los demás se realinean', function (): void {
    [$routeId, $shipments] = srsRoute(['PENDING', 'PENDING', 'PENDING']);
    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId));

    DB::table('execution_driver_route_stops')->where('shipment_id', $shipments[0])->delete();
    app(CommandBus::class)->dispatch(new SyncRouteStops($routeId, $shipments[0]));

    expect(srsTrackings()->findByShipmentId($shipments[0])->routeId())->toBeNull()
        ->and(srsTrackings()->findByShipmentId($shipments[0])->remainingStops())->toBe(0)
        ->and(srsTrackings()->findByShipmentId($shipments[1])->remainingStops())->toBe(0)
        ->and(srsTrackings()->findByShipmentId($shipments[2])->remainingStops())->toBe(1);
});
