<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Línea de costo de un TERCERO (transportista/carrier) en un período: lo liquidado al carrier según el
 * contexto carriers (`carrier_settlements`), con cuántas liquidaciones y cuántos corredores distintos.
 * El nombre del carrier se resuelve read-only desde `carriers` (lectura cruzada; sin dependencia composer).
 */
final readonly class CarrierCostLineView
{
    public function __construct(
        public string $carrierId,
        public ?string $carrierName,
        public int $settlements,
        public int $corridors,
        public int $costCents,
        public string $currency,
    ) {}

    /**
     * @return array{carrier_id: string, carrier_name: string|null, settlements: int, corridors: int, cost_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'carrier_name' => $this->carrierName,
            'settlements' => $this->settlements,
            'corridors' => $this->corridors,
            'cost_cents' => $this->costCents,
            'currency' => $this->currency,
        ];
    }
}
