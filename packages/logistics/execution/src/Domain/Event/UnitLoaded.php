<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cargó UN bulto del envío al vehículo (escaneo de carga validada).
 *
 * A diferencia de {@see ShipmentLoaded} —que sale UNA sola vez, en la transición del primer bulto— este hecho
 * liviano sale por CADA escaneo de carga, para que los observadores de tiempo real reflejen en vivo cada bulto
 * que el andén ya subió al camión (dos operarios del mismo envío se ven mutuamente). No lo consume ningún
 * reactor: solo se persiste en el outbox y lo leen los observadores de realtime (p. ej. `loading.updated`).
 */
final readonly class UnitLoaded extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $vehicleId,
        public string $unitId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.unit_loaded';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
            'unit_id' => $this->unitId,
        ];
    }
}
