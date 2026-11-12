<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\CountUnreadNotifications;

use Logistics\Shared\Application\Query\Query;

/**
 * Cuenta las notificaciones no leídas por un usuario (badge de la campana de la app).
 */
final readonly class CountUnreadNotifications implements Query
{
    public function __construct(
        public string $userId,
    ) {}
}
