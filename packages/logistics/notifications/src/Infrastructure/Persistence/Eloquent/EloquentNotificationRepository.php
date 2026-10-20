<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Psr\Log\LoggerInterface;
use Throwable;

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
        private readonly ?LoggerInterface $logger = null,
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

    public function existsForShipmentAndType(string $shipmentId, MessageType $messageType): bool
    {
        return NotificationRecord::query()
            ->where('shipment_id', $shipmentId)
            ->where('message_type', $messageType->value)
            ->exists();
    }

    public function findByShipment(string $shipmentId): array
    {
        return $this->toDomainList(
            NotificationRecord::query()
                ->where('shipment_id', $shipmentId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    public function latest(int $limit = 100, int $offset = 0): array
    {
        return $this->toDomainList(
            NotificationRecord::query()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->offset(max(0, $offset))
                ->limit(max(1, $limit))
                ->get(),
        );
    }

    public function count(): int
    {
        return NotificationRecord::query()->count();
    }

    /**
     * Mapea un listado tolerando filas no reconstruibles (p. ej. un canal histórico que ya no existe):
     * la fila se omite con un warning en vez de tumbar el listado entero con un 500.
     *
     * @param  iterable<NotificationRecord> $records
     * @return list<Notification>
     */
    private function toDomainList(iterable $records): array
    {
        $notifications = [];
        foreach ($records as $record) {
            try {
                $notifications[] = $this->mapper->toDomain($record);
            } catch (Throwable $e) {
                $this->logger?->warning('Notificación omitida del listado: fila no reconstruible', [
                    'notification_id' => $record->id,
                    'current_channel' => $record->current_channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notifications;
    }
}
