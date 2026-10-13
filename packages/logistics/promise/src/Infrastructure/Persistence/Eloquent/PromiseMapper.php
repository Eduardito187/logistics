<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;

/**
 * Traduce entre el agregado DeliveryPromise y su fila Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class PromiseMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(DeliveryPromise $promise): array
    {
        $window = $promise->reservedUntil();

        return [
            'id' => $promise->id()->toString(),
            'shipment_ref' => $promise->shipmentRef(),
            'zone_id' => $promise->zoneId(),
            'slot_date' => $promise->slot()->dateString(),
            'time_slot' => $promise->slot()->timeSlot,
            'status' => $promise->status()->value,
            'reserved_until' => $window?->expiresAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function toDomain(PromiseRecord $record): DeliveryPromise
    {
        $slot = DeliverySlot::of(
            new DateTimeImmutable($record->slot_date),
            $record->time_slot,
        );

        $reservedUntil = $record->reserved_until !== null
            ? ReservationWindow::until(new DateTimeImmutable($record->reserved_until))
            : null;

        return DeliveryPromise::reconstitute(
            PromiseId::fromString($record->id),
            $record->shipment_ref,
            $record->zone_id,
            $slot,
            PromiseStatus::from($record->status),
            $reservedUntil,
        );
    }
}
