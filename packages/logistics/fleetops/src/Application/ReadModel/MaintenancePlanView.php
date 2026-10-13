<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use Logistics\FleetOps\Domain\Model\MaintenancePlan;

/**
 * Proyección de lectura de un plan de mantenimiento preventivo para listados y API. Primitivos: no
 * expone objetos de dominio. Las fechas van en ISO 8601 (o null).
 */
final readonly class MaintenancePlanView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public string $serviceKind,
        public ?int $intervalKm,
        public ?int $intervalDays,
        public ?int $lastDoneKm,
        public ?string $lastDoneAt,
        public ?int $nextDueKm,
        public ?string $nextDueAt,
        public bool $active,
    ) {}

    public static function fromDomain(MaintenancePlan $plan): self
    {
        return new self(
            $plan->id()->toString(),
            $plan->vehicleId(),
            $plan->serviceKind()->value,
            $plan->intervalKm(),
            $plan->intervalDays(),
            $plan->lastDoneKm(),
            $plan->lastDoneAt()?->format(DATE_ATOM),
            $plan->nextDueKm(),
            $plan->nextDueAt()?->format(DATE_ATOM),
            $plan->isActive(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, service_kind: string, interval_km: int|null, interval_days: int|null, last_done_km: int|null, last_done_at: string|null, next_due_km: int|null, next_due_at: string|null, active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'service_kind' => $this->serviceKind,
            'interval_km' => $this->intervalKm,
            'interval_days' => $this->intervalDays,
            'last_done_km' => $this->lastDoneKm,
            'last_done_at' => $this->lastDoneAt,
            'next_due_km' => $this->nextDueKm,
            'next_due_at' => $this->nextDueAt,
            'active' => $this->active,
        ];
    }
}
