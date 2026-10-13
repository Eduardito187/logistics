<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\Model\Notification;
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
     * Notificaciones de un envío, de la más reciente a la más antigua.
     *
     * @return list<Notification>
     */
    public function findByShipment(string $shipmentId): array;
}
