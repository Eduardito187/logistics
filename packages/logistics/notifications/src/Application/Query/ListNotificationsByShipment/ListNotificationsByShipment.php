<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotificationsByShipment;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Lista las notificaciones de un envío (de la más reciente a la más antigua) como NotificationView.
 *
 * `scope`: alcance por rol de quien consulta (lo fija la puerta app). Si el envío queda fuera de ese
 * alcance la lista sale vacía. `null` = sin restricción (puerta de canales, procesos internos).
 */
final readonly class ListNotificationsByShipment implements Query
{
    public function __construct(
        public string $shipmentId,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
