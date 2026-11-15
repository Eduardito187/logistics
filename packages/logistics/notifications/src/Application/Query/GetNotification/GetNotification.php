<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetNotification;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Obtiene una notificación por su id como NotificationView, o null si no existe. Con `userId` incluye
 * el estado de lectura de ese usuario (read_at).
 *
 * `scope`: alcance por rol de quien consulta (lo fija la puerta HTTP). Si la notificación es de un envío
 * fuera de ese alcance la respuesta es null, igual que si no existiera (no se revela). `null` = sin
 * restricción.
 */
final readonly class GetNotification implements Query
{
    public function __construct(
        public string $notificationId,
        public ?string $userId = null,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
