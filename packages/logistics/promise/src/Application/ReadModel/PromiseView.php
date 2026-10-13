<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

use DateTimeImmutable;
use Logistics\Promise\Domain\Model\DeliveryPromise;

/**
 * Modelo de lectura de la promesa: forma plana en primitivos para APIs de canales y paneles.
 *
 * Se arma desde el agregado con fromPromise(); las consultas nunca exponen el objeto de dominio.
 */
final readonly class PromiseView
{
    public function __construct(
        public string $id,
        public string $shipmentRef,
        public string $zoneId,
        public string $slotDate,
        public string $timeSlot,
        public string $status,
        public ?string $reservedUntil,
    ) {}

    public static function fromPromise(DeliveryPromise $promise): self
    {
        $window = $promise->reservedUntil();

        return new self(
            $promise->id()->toString(),
            $promise->shipmentRef(),
            $promise->zoneId(),
            $promise->slot()->dateString(),
            $promise->slot()->timeSlot,
            $promise->status()->value,
            $window?->expiresAt->format(DateTimeImmutable::ATOM),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_ref' => $this->shipmentRef,
            'zone_id' => $this->zoneId,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
            'status' => $this->status,
            'reserved_until' => $this->reservedUntil,
        ];
    }
}
