<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotificationsByShipment;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las notificaciones de un envío (de la más reciente a la más antigua) como NotificationView.
 */
final readonly class ListNotificationsByShipment implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
