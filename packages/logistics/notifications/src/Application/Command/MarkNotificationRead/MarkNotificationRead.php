<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkNotificationRead;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Comando para marcar una notificación como leída por un usuario. Idempotente: repetirlo no cambia el
 * instante de lectura original. El userId lo pone el servidor (usuario autenticado).
 *
 * `scope`: alcance por rol del usuario (lo fija la puerta HTTP). Una notificación de un envío fuera de ese
 * alcance se trata como inexistente. `null` = sin restricción.
 */
final readonly class MarkNotificationRead implements Command
{
    public function __construct(
        public string $notificationId,
        public string $userId,
        public ?ShipmentScopeActor $scope = null,
    ) {}
}
