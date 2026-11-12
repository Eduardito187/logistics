<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkAllNotificationsRead;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para marcar como leídas todas las notificaciones de un usuario creadas hasta `upTo`
 * (ISO 8601; null = ahora). Evita que «leer todas» marque una notificación que llegó después de que el
 * usuario vio la lista: la app manda el created_at más nuevo que tenía en pantalla.
 */
final readonly class MarkAllNotificationsRead implements Command
{
    public function __construct(
        public string $userId,
        public ?string $upTo = null,
    ) {}
}
