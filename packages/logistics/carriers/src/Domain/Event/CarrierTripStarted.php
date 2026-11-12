<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El transportista (u oficina en su nombre) inició el viaje: la carga salió del origen.
 *
 * Lleva los envíos de la carga para que los reactores (tracking, notificaciones) los pongan en tránsito
 * sin consultar de vuelta a este contexto.
 */
final readonly class CarrierTripStarted extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $startedAt,
        public ?string $actorId,
        public array $shipmentIds,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_started';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'corridor_from' => $this->corridorFrom,
            'corridor_to' => $this->corridorTo,
            'started_at' => $this->startedAt,
            'actor_id' => $this->actorId,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
