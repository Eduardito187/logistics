<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\ListNotifications;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Lista las últimas notificaciones de toda la operación (log global), de la más reciente a la más
 * antigua, como NotificationView.
 *
 * Con `userId` cada ítem trae el estado de lectura de ESE usuario (read_at); con `unreadOnly` (y un
 * usuario) se filtran solo las no leídas por él. Ambos son aditivos: sin ellos, el listado es el de
 * siempre.
 *
 * `scope`: alcance por rol de quien consulta (lo fija la puerta HTTP desde el usuario autenticado). Solo
 * entran las notificaciones de envíos que ese actor puede ver; la página y el total cuentan solo eso.
 * `null` = sin restricción (procesos internos).
 */
final readonly class ListNotifications implements Query
{
    public function __construct(
        public int $limit = 100,
        public int $offset = 0,
        public ?string $userId = null,
        public bool $unreadOnly = false,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
