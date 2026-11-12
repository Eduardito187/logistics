<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Un plan de mantenimiento preventivo que está por vencer o ya vencido para un vehículo, con el dato de
 * odómetro actual que lo determinó. Alimenta el tablero, la app y la alerta al supervisor.
 *
 * Forma ESTABLE (auditoría #24, schema OpenAPI `DueMaintenanceItem`): la app arma la pantalla con estas
 * claves, así que solo se agregan claves nuevas, nunca se renombran. `vehicle_plate`, `remaining_km`,
 * `days_remaining`, `urgency` y `plan_id` son aditivas: la app ya no tiene que adivinar ni calcular.
 */
final readonly class DueMaintenanceView
{
    public const string URGENCY_OVERDUE = 'overdue';

    public const string URGENCY_DUE_SOON = 'due_soon';

    public function __construct(
        public string $vehicleId,
        public string $serviceKind,
        public ?int $nextDueKm,
        public ?string $nextDueAt,
        public ?int $currentOdometerKm,
        public bool $overdue,
        public ?string $planId = null,
        public ?string $vehiclePlate = null,
        public ?int $daysRemaining = null,
    ) {}

    /**
     * Copia con la placa legible resuelta (la proyección se arma antes de resolver placas en lote).
     */
    public function withPlate(?string $plate): self
    {
        return new self(
            $this->vehicleId,
            $this->serviceKind,
            $this->nextDueKm,
            $this->nextDueAt,
            $this->currentOdometerKm,
            $this->overdue,
            $this->planId,
            $plate,
            $this->daysRemaining,
        );
    }

    /**
     * Km que faltan para el próximo servicio (negativo si ya se pasó); null si el plan no es por km o no
     * hay odómetro.
     */
    public function remainingKm(): ?int
    {
        if ($this->nextDueKm === null || $this->currentOdometerKm === null) {
            return null;
        }

        return $this->nextDueKm - $this->currentOdometerKm;
    }

    public function urgency(): string
    {
        return $this->overdue ? self::URGENCY_OVERDUE : self::URGENCY_DUE_SOON;
    }

    /**
     * @return array{vehicle_id: string, vehicle_plate: string|null, plan_id: string|null, service_kind: string, next_due_km: int|null, next_due_at: string|null, current_odometer_km: int|null, remaining_km: int|null, days_remaining: int|null, overdue: bool, urgency: string}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'vehicle_plate' => $this->vehiclePlate,
            'plan_id' => $this->planId,
            'service_kind' => $this->serviceKind,
            'next_due_km' => $this->nextDueKm,
            'next_due_at' => $this->nextDueAt,
            'current_odometer_km' => $this->currentOdometerKm,
            'remaining_km' => $this->remainingKm(),
            'days_remaining' => $this->daysRemaining,
            'overdue' => $this->overdue,
            'urgency' => $this->urgency(),
        ];
    }
}
