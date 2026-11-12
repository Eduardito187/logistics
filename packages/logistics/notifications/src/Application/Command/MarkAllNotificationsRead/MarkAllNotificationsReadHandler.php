<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkAllNotificationsRead;

use DateTimeImmutable;
use Exception;
use Logistics\Notifications\Domain\Contract\NotificationReadStateRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Marca como leídas todas las notificaciones del usuario hasta un instante. Se guarda como marca de
 * agua por usuario (una fila), no una fila por notificación: O(1) aunque el log tenga millones.
 *
 * Un `upTo` futuro se recorta a "ahora" (no se pueden dar por leídas notificaciones que aún no existen).
 *
 * @implements CommandHandler<MarkAllNotificationsRead>
 */
final class MarkAllNotificationsReadHandler implements CommandHandler
{
    public function __construct(
        private readonly NotificationReadStateRepository $readStates,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkAllNotificationsRead) {
            throw NotificationsException::because('MarkAllNotificationsReadHandler solo maneja MarkAllNotificationsRead');
        }

        $userId = trim($command->userId);
        if ($userId === '') {
            throw NotificationsException::because('marcar todas como leídas necesita un usuario');
        }

        $now = $this->clock->now();
        $upTo = $now;

        if ($command->upTo !== null && trim($command->upTo) !== '') {
            try {
                $requested = new DateTimeImmutable($command->upTo);
            } catch (Exception) {
                throw NotificationsException::because("fecha up_to inválida: {$command->upTo}");
            }

            $upTo = $requested < $now ? $requested : $now;
        }

        $this->readStates->markAllRead($userId, $upTo, $now);
    }
}
