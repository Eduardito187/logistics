<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Contract;

use DateTimeImmutable;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;

/**
 * Puerta de persistencia de los avisos a SAP. La idempotencia por `event_id` se respalda en el índice
 * único de la tabla: registrar dos veces el mismo hecho es un no-op.
 */
interface SapNotificationRepository
{
    /**
     * Reserva una identidad nueva (UUID v7); no la genera la base.
     */
    public function nextIdentity(): SapNotificationId;

    /**
     * Inserta el aviso SOLO si no existe otro con el mismo event_id (insert-or-ignore). Devuelve true si
     * se insertó, false si ya estaba registrado.
     */
    public function registerIfAbsent(SapNotification $notification): bool;

    /**
     * Persiste el nuevo estado de un aviso ya registrado.
     */
    public function save(SapNotification $notification): void;

    public function findById(SapNotificationId $id): ?SapNotification;

    public function findByEventId(string $eventId): ?SapNotification;

    /**
     * Avisos a reintentar: Pending/Failed con intentos < $maxAttempts y cuyo próximo intento ya venció
     * (backoff cumplido). Ordenados del más viejo al más nuevo, acotados a $limit (lotes chicos).
     *
     * @return list<SapNotification>
     */
    public function dueForRetry(int $maxAttempts, DateTimeImmutable $now, int $limit): array;
}
