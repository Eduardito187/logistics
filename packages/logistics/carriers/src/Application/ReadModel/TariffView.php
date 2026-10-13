<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

use Logistics\Carriers\Domain\Model\Tariff;

/**
 * Proyección de lectura de una tarifa para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class TariffView
{
    private function __construct(
        public string $id,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $vehicleClass,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
        // Nombre legible del transportista (tabla carriers.name) resuelto en el QueryHandler.
        // Null si el transportista ya no existe; el UUID crudo se mantiene en carrier_id.
        public ?string $carrierName = null,
    ) {}

    public static function fromDomain(Tariff $tariff, ?string $carrierName = null): self
    {
        return new self(
            $tariff->id()->toString(),
            $tariff->carrierId(),
            $tariff->corridor()->from,
            $tariff->corridor()->to,
            $tariff->corridor()->type,
            $tariff->vehicleClass()->value,
            $tariff->price()->cents,
            $tariff->price()->currency,
            $tariff->effectiveFrom()->format('Y-m-d'),
            $carrierName,
        );
    }

    /**
     * @return array{id: string, carrier_id: string, carrier_name: string|null, corridor_from: string, corridor_to: string, corridor_type: string, vehicle_class: string, price_cents: int, currency: string, effective_from: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'carrier_id' => $this->carrierId,
            'carrier_name' => $this->carrierName,
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
