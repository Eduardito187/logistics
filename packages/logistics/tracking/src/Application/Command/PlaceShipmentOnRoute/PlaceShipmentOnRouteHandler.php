<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\PlaceShipmentOnRoute;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;

/**
 * Proyecta la posición de un envío en la ruta publicada sobre su seguimiento.
 *
 * Tolerante por diseño (es una proyección): si todavía no se abrió seguimiento para ese envío, no hace
 * nada en vez de fallar, así el relay puede reenviar sin que un envío aún no seguido tumbe la
 * proyección de toda la ruta. El agregado deriva stops_remaining de la posición y, si no es terminal,
 * graba ShipmentPlacedOnRoute para que el canal pueda avisar "tu pedido está a N paradas".
 *
 * @implements CommandHandler<PlaceShipmentOnRoute>
 */
final class PlaceShipmentOnRouteHandler implements CommandHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof PlaceShipmentOnRoute) {
            throw TrackingException::because('PlaceShipmentOnRouteHandler solo maneja PlaceShipmentOnRoute');
        }

        $tracking = $this->trackings->findByShipmentId($command->shipmentId);
        if ($tracking === null) {
            // Todavía no se abrió el seguimiento de este envío: nada que ubicar.
            return;
        }

        $tracking->placeOnRoute($command->routeId, RoutePosition::of($command->routePosition));

        $this->trackings->save($tracking);

        $this->recordEvents->record($tracking->releaseEvents(), 'tracking.shipment_tracking');
    }
}
