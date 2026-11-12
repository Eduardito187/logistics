<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las últimas notificaciones de toda la operación (log global), de la más reciente a la más
 * antigua, como NotificationView.
 *
 * Con `userId` cada ítem trae el estado de lectura de ESE usuario (read_at); con `unreadOnly` (y un
 * usuario) se filtran solo las no leídas por él. Ambos son aditivos: sin ellos, el listado es el de
 * siempre.
 */
final readonly class ListNotifications implements Query
{
    public function __construct(
        public int $limit = 100,
        public int $offset = 0,
        public ?string $userId = null,
        public bool $unreadOnly = false,
    ) {}
}
