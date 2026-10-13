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
        public ?string $zoneName,
        public string $slotDate,
        public string $timeSlot,
        public string $status,
        public ?string $reservedUntil,
    ) {}

    /**
     * El nombre legible de la zona (coverage) no vive en el agregado de promise —este contexto solo
     * conoce la zona como id primitivo—, así que se resuelve en el QueryHandler y se pasa como argumento.
     * Null si no se pudo resolver (zona inexistente).
     */
    public static function fromPromise(DeliveryPromise $promise, ?string $zoneName = null): self
    {
        $window = $promise->reservedUntil();

        return new self(
            $promise->id()->toString(),
            $promise->shipmentRef(),
            $promise->zoneId(),
            $zoneName,
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
            'zone_name' => $this->zoneName,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
            'status' => $this->status,
            'reserved_until' => $this->reservedUntil,
        ];
    }
}
