<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;

/**
 * Puerta de persistencia del agregado Notification. El dominio la define; la implementación Eloquent
 * vive en Infrastructure.
 */
interface NotificationRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): NotificationId;

    public function save(Notification $notification): void;

    public function findById(NotificationId $id): ?Notification;

    /**
     * ¿Ya existe una notificación para este envío y tipo de mensaje? Lo usa el wiring por eventos de
     * dominio para ser idempotente: un mismo hito del pedido no debe notificarse dos veces, aunque el
     * outbox reentregue el evento (at-least-once).
     */
    public function existsForShipmentAndType(string $shipmentId, MessageType $messageType): bool;

    /**
     * Notificaciones de un envío, de la más reciente a la más antigua.
     *
     * @return list<Notification>
     */
    public function findByShipment(string $shipmentId): array;

    /**
     * Últimas notificaciones de toda la operación, de la más reciente a la más antigua (para el log),
     * paginadas por limit/offset (recorte en SQL).
     *
     * @return list<Notification>
     */
    public function latest(int $limit = 100, int $offset = 0): array;

    /**
     * Total de notificaciones del log global, para el total de la paginación (COUNT(*) aparte).
     */
    public function count(): int;
}
