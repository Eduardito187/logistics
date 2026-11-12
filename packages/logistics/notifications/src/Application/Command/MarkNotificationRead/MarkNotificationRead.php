<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkNotificationRead;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para marcar una notificación como leída por un usuario. Idempotente: repetirlo no cambia el
 * instante de lectura original. El userId lo pone el servidor (usuario autenticado).
 */
final readonly class MarkNotificationRead implements Command
{
    public function __construct(
        public string $notificationId,
        public string $userId,
    ) {}
}
