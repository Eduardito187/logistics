<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Un plan de mantenimiento preventivo que está por vencer o ya vencido para un vehículo, con el dato de
 * odómetro actual que lo determinó. Alimenta el tablero y la alerta al supervisor.
 */
final readonly class DueMaintenanceView
{
    public function __construct(
        public string $vehicleId,
        public string $serviceKind,
        public ?int $nextDueKm,
        public ?string $nextDueAt,
        public ?int $currentOdometerKm,
        public bool $overdue,
    ) {}

    /**
     * @return array{vehicle_id: string, service_kind: string, next_due_km: int|null, next_due_at: string|null, current_odometer_km: int|null, overdue: bool}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'service_kind' => $this->serviceKind,
            'next_due_km' => $this->nextDueKm,
            'next_due_at' => $this->nextDueAt,
            'current_odometer_km' => $this->currentOdometerKm,
            'overdue' => $this->overdue,
        ];
    }
}
