<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use DateTimeImmutable;
use Logistics\Notifications\Domain\ValueObject\NotificationId;

/**
 * Puerta de escritura del estado de lectura POR USUARIO de las notificaciones (lado comando del CQRS).
 *
 * El estado de lectura es del usuario, no de la notificación: marcar leída en un teléfono se refleja
 * en los demás dispositivos del MISMO usuario y nunca afecta a otro usuario. El id del usuario lo pone
 * siempre el servidor (usuario autenticado), jamás el cliente.
 */
interface NotificationReadStateRepository
{
    /**
     * Marca una notificación como leída para el usuario. Idempotente: si ya estaba marcada conserva el
     * primer instante de lectura.
     */
    public function markRead(NotificationId $notificationId, string $userId, DateTimeImmutable $readAt): void;

    /**
     * Marca como leídas todas las notificaciones del usuario creadas hasta `$upTo` (inclusive).
     * Monótona: una marca anterior a la vigente no retrocede el estado.
     */
    public function markAllRead(string $userId, DateTimeImmutable $upTo, DateTimeImmutable $markedAt): void;
}
