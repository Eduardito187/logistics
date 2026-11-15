<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\SuppressNotification;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Comando para suprimir una notificación: no se enviará (por tope de SMS, por reglas o porque dejó de
 * ser relevante). Solo aplica a notificaciones que aún no se enviaron.
 *
 * `scope`: alcance por rol de quien la suprime desde la app (lo fija la puerta HTTP). Una notificación de
 * un envío fuera de ese alcance se trata como inexistente. `null` = sin restricción (admin, procesos).
 */
final readonly class SuppressNotification implements Command
{
    public function __construct(
        public string $notificationId,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
