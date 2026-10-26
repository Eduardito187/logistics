<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las últimas notificaciones de toda la operación (log global), de la más reciente a la más
 * antigua, como NotificationView.
 */
final readonly class ListNotifications implements Query
{
    public function __construct(
        public int $limit = 100,
        public int $offset = 0,
    ) {}
}
