<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió (o actualizó) una tarifa de un transportista para un corredor y clase de vehículo.
 */
final readonly class TariffSet extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $vehicleClass,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.tariff_set';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'corridor_from' => $this->corridorFrom,
            'corridor_to' => $this->corridorTo,
            'corridor_type' => $this->corridorType,
            'vehicle_class' => $this->vehicleClass,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom,
        ];
    }
}
