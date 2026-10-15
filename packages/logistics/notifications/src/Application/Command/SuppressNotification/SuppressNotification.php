<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\SuppressNotification;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para suprimir una notificación: no se enviará (por tope de SMS, por reglas o porque dejó de
 * ser relevante). Solo aplica a notificaciones que aún no se enviaron.
 */
final readonly class SuppressNotification implements Command
{
    public function __construct(
        public string $notificationId,
    ) {}
}
