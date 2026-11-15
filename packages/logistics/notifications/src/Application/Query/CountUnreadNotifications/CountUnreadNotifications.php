<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\CountUnreadNotifications;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Cuenta las notificaciones no leídas por un usuario (badge de la campana de la app).
 *
 * `scope`: alcance por rol de quien consulta (lo fija la puerta HTTP desde el usuario autenticado); solo se
 * cuentan las notificaciones de envíos que ese actor puede ver. `null` = sin restricción.
 */
final readonly class CountUnreadNotifications implements Query
{
    public function __construct(
        public string $userId,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
