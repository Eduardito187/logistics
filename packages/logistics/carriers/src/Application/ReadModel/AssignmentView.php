<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

/**
 * Proyección de lectura de una asignación de corredor por volumen: la clase de vehículo elegida y la
 * tarifa más barata disponible para esa clase (o null si todavía no hay ninguna cargada).
 *
 * Primitivos: no expone objetos de dominio. La usa planning para consolidar un corredor.
 */
final readonly class AssignmentView
{
    public function __construct(
        public float $volumeM3,
        public string $vehicleClass,
        public ?TariffView $tariff,
    ) {}

    /**
     * @return array{volume_m3: float, vehicle_class: string, tariff: array{id: string, carrier_id: string, carrier_name: string|null, corridor_from: string, corridor_to: string, corridor_type: string, vehicle_class: string, price_cents: int, currency: string, effective_from: string}|null}
     */
    public function toArray(): array
    {
        return [
            'volume_m3' => $this->volumeM3,
            'vehicle_class' => $this->vehicleClass,
            'tariff' => $this->tariff?->toArray(),
        ];
    }
}
