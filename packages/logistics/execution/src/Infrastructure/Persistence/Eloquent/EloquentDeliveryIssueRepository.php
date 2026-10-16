<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeInterface;
use Logistics\Execution\Domain\Contract\DeliveryIssueRepository;
use Logistics\Execution\Domain\Model\DeliveryIssue;
use Logistics\Execution\Domain\ValueObject\DeliveryIssueId;

/**
 * Implementación Eloquent del repositorio de inconvenientes de entrega.
 *
 * El inconveniente es un hecho inmutable: save() inserta la fila (upsert por id para tolerar reintentos
 * del mismo agregado). Toda la conversión a primitivos vive acá; el dominio no conoce la forma de la tabla.
 */
final class EloquentDeliveryIssueRepository implements DeliveryIssueRepository
{
    public function nextIdentity(): DeliveryIssueId
    {
        return DeliveryIssueId::generate();
    }

    public function save(DeliveryIssue $issue): void
    {
        DeliveryIssueRecord::query()->updateOrCreate(
            ['id' => $issue->id()->toString()],
            [
                'shipment_id' => $issue->shipmentId(),
                'type' => $issue->type()->value,
                'note' => $issue->note(),
                'lat' => $issue->location()?->lat,
                'lng' => $issue->location()?->lng,
                'photo_path' => $issue->photoPath(),
                'reported_by_user' => $issue->reportedBy()->userId,
                'reported_by_device' => $issue->reportedBy()->deviceId,
                'occurred_at' => $issue->occurredAt()->format(DateTimeInterface::ATOM),
                'idempotency_key' => $issue->idempotencyKey(),
            ],
        );
    }

    public function existsByIdempotencyKey(?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return false;
        }

        return DeliveryIssueRecord::query()
            ->where('idempotency_key', trim($idempotencyKey))
            ->exists();
    }
}
