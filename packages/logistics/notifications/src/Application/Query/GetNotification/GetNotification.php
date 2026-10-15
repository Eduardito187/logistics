<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetNotification;

use Logistics\Shared\Application\Query\Query;

/**
 * Obtiene una notificación por su id como NotificationView, o null si no existe.
 */
final readonly class GetNotification implements Query
{
    public function __construct(
        public string $notificationId,
    ) {}
}
