<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\AdvanceCascade;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para avanzar la cascada de una notificación: si no hubo apertura en el canal actual, escala
 * al siguiente. Lo dispara el relay del outbox (o un job) al vencer la ventana de espera sin apertura.
 */
final readonly class AdvanceCascade implements Command
{
    public function __construct(
        public string $notificationId,
    ) {}
}
