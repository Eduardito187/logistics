<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Resultado del empaque para UN camión: el vehículo, el departamento al que quedó ligado y los pedidos
 * que le tocaron (todavía SIN rutear; el orden fino lo pone después el motor de rutas por cercanía).
 *
 * Es un dato puro de salida del DailyPlanComposer; el runner lo convierte en un DeliveryPlan.
 */
final readonly class TruckLoad
{
    /**
     * @param list<DeliveryCandidate> $candidates
     */
    public function __construct(
        public string $vehicleId,
        public ?string $departmentId,
        public array $candidates,
        public float $usedKg,
        public float $usedM3,
    ) {}

    public function stopCount(): int
    {
        return count($this->candidates);
    }
}
