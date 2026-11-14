<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\SyncRouteStops;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Application\Contract\RouteStopsReader;
use Logistics\Tracking\Application\Service\EnsureShipmentTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;

/**
 * Proyecta la ruta real del camión sobre el seguimiento de sus envíos.
 *
 * Por cada parada PENDIENTE: garantiza que el envío tenga seguimiento (lo abre si faltaba), fija su ruta y
 * posición y cuenta las paradas pendientes que tiene por delante. Las paradas ya resueltas no se tocan: su
 * seguimiento lo cierra el hecho de entrega/fallo. El conteo sale de la tabla de paradas (fuente de
 * verdad), así que reproyectar deja siempre el mismo resultado.
 *
 * Tolerante por diseño (es una proyección): un envío sin referencia de orden se saltea en vez de tumbar la
 * ruta entera.
 *
 * @implements CommandHandler<SyncRouteStops>
 */
final class SyncRouteStopsHandler implements CommandHandler
{
    public function __construct(
        private readonly RouteStopsReader $stops,
        private readonly EnsureShipmentTracking $ensureTracking,
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SyncRouteStops) {
            throw TrackingException::because('SyncRouteStopsHandler solo maneja SyncRouteStops');
        }

        $routeId = trim($command->routeId);
        if ($routeId === '') {
            return;
        }

        $stops = $this->stops->stopsOfRoute($routeId);
        $onRoute = [];
        $pendingAhead = 0;

        foreach ($stops as $stop) {
            $onRoute[$stop['shipment_id']] = true;

            if (!$stop['pending']) {
                continue;
            }

            $tracking = $this->ensureTracking->forShipment($stop['shipment_id'], $command->silent);
            if ($tracking !== null) {
                $tracking->syncRouteProgress($routeId, RoutePosition::of(max(1, $stop['sequence'])), $pendingAhead);
                $this->trackings->save($tracking);
                $events = $tracking->releaseEvents();
                if (!$command->silent) {
                    $this->recordEvents->record($events, 'tracking.shipment_tracking');
                }
            }

            $pendingAhead++;
        }

        // El envío que salió de esta ruta deja de mostrar sus paradas (otra ruta lo ubicará si lo toma).
        $detached = $command->detachedShipmentId;
        if ($detached !== null && $detached !== '' && !isset($onRoute[$detached])) {
            $tracking = $this->trackings->findByShipmentId($detached);
            if ($tracking !== null) {
                $tracking->leaveRoute($routeId);
                $this->trackings->save($tracking);
            }
        }
    }
}
