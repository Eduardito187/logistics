<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\NotificationId;

/**
 * Implementación Eloquent del repositorio de notificaciones.
 *
 * save() es un upsert por id: reescribe la fila con el estado actual del agregado (política e intentos
 * incluidos, como jsonb).
 */
final class EloquentNotificationRepository implements NotificationRepository
{
    public function __construct(
        private readonly NotificationMapper $mapper,
    ) {}

    public function nextIdentity(): NotificationId
    {
        return NotificationId::generate();
    }

    public function save(Notification $notification): void
    {
        NotificationRecord::query()->updateOrCreate(
            ['id' => $notification->id()->toString()],
            $this->mapper->toRow($notification),
        );
    }

    public function findById(NotificationId $id): ?Notification
    {
        $record = NotificationRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipment(string $shipmentId): array
    {
        return NotificationRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (NotificationRecord $record): Notification => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
