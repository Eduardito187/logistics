<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

use DateTimeInterface;
use Logistics\Carriers\Domain\Model\CarrierTrip;

/**
 * Proyección de lectura del ESTADO de un viaje de transportista (respuesta de iniciar/cerrar y
 * autorización por dueño). Primitivos: no expone objetos de dominio.
 */
final readonly class CarrierTripView
{
    private function __construct(
        public string $id,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public ?string $reference,
        public string $status,
        public string $scheduledAt,
        public ?string $startedAt,
        public ?string $closedAt,
        public ?string $cancelledAt,
        public int $shipmentsCount,
    ) {}

    public static function fromDomain(CarrierTrip $trip): self
    {
        return new self(
            $trip->id()->toString(),
            $trip->carrierId(),
            $trip->corridorFrom(),
            $trip->corridorTo(),
            $trip->reference(),
            $trip->status()->value,
            $trip->scheduledAt()->format(DateTimeInterface::ATOM),
            $trip->startedAt()?->format(DateTimeInterface::ATOM),
            $trip->closedAt()?->format(DateTimeInterface::ATOM),
            $trip->cancelledAt()?->format(DateTimeInterface::ATOM),
            \count($trip->shipmentIds()),
        );
    }

    /**
     * @return array{trip_id: string, carrier_id: string, reference: string|null, status: string, scheduled_at: string, started_at: string|null, closed_at: string|null, cancelled_at: string|null, shipments_count: int}
     */
    public function toArray(): array
    {
        return [
            'trip_id' => $this->id,
            'carrier_id' => $this->carrierId,
            'reference' => $this->reference,
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt,
            'started_at' => $this->startedAt,
            'closed_at' => $this->closedAt,
            'cancelled_at' => $this->cancelledAt,
            'shipments_count' => $this->shipmentsCount,
        ];
    }
}
