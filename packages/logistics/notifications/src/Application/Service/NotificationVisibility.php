<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Service;

use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * ¿Puede el actor ver la notificación de ESE envío? Una notificación es un aviso al cliente anclado a un
 * envío, así que hereda el alcance por rol de los envíos. La regla NO se repite acá: se le pregunta al
 * lector de alcance de Envíos ({@see ShipmentScopeReader}), el mismo que usa el resto de la plataforma.
 *
 * Sin actor (procesos internos, integraciones, admin Filament) o con un actor sin restricción, cualquier
 * notificación es visible. Un actor restringido NO ve la notificación de un envío fuera de su alcance ni la de un envío que
 * ya no existe (notificación huérfana): esas quedan solo para admin / supervisor.
 */
final readonly class NotificationVisibility
{
    public function __construct(
        private ShipmentScopeReader $shipmentScope,
    ) {}

    public function canSeeShipment(?ShipmentScopeActor $actor, string $shipmentId): bool
    {
        if ($actor === null || $actor->unrestricted) {
            return true;
        }

        if ($actor->seesNothing()) {
            return false;
        }

        // El lector descarta los ids sin forma de UUID (nunca se comparan contra una columna uuid).
        return $this->shipmentScope->inScope($actor, [$shipmentId]) !== [];
    }
}
