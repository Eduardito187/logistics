<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Service\RouteQualityScorer;
use Logistics\Planning\Domain\ValueObject\RouteQualityScore;

/**
 * Modelo de lectura de las métricas de CALIDAD de un plan (R-06): distancia total, tiempo estimado y
 * ocupación del camión (carga kg/m3 contra capacidad). Forma plana en primitivos para APIs y paneles.
 *
 * La distancia y el tiempo salen de la geometría ya calculada y persistida con el plan (lo que
 * efectivamente recorre el camión); si el plan todavía no tiene geometría, quedan en 0. La carga y la
 * capacidad las aporta el QueryHandler leyendo los otros contextos read-only.
 */
final readonly class RouteQualityView
{
    public function __construct(
        public string $planId,
        public string $planDate,
        public string $vehicleId,
        public string $status,
        public int $stopCount,
        public RouteQualityScore $score,
    ) {}

    /**
     * Arma la vista desde el agregado y la carga/capacidad resueltas por el handler. La distancia y el
     * tiempo se toman de la geometría persistida del plan (0 si aún no se calculó).
     */
    public static function fromPlan(
        DeliveryPlan $plan,
        RouteQualityScorer $scorer,
        float $loadKg,
        float $loadM3,
        ?float $capacityKg,
        ?float $capacityM3,
    ): self {
        $geometry = $plan->geometry();
        $distanceMeters = $geometry !== null ? $geometry->distanceMeters : 0.0;
        $durationSeconds = $geometry !== null ? $geometry->durationSeconds : 0.0;

        $score = $scorer->score(
            $distanceMeters,
            $durationSeconds,
            $loadKg,
            $loadM3,
            $capacityKg,
            $capacityM3,
        );

        return new self(
            $plan->id()->toString(),
            $plan->date()->toString(),
            $plan->vehicleId(),
            $plan->status()->value,
            count($plan->stops()),
            $score,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status,
            'stop_count' => $this->stopCount,
            'distance_meters' => $this->score->distanceMeters,
            'duration_seconds' => $this->score->durationSeconds,
            // Minutos estimados, para mostrar sin recalcular en el cliente.
            'duration_minutes' => (int) round($this->score->durationSeconds / 60),
            'load_kg' => $this->score->loadKg,
            'load_m3' => $this->score->loadM3,
            'capacity_kg' => $this->score->capacityKg,
            'capacity_m3' => $this->score->capacityM3,
            // Ocupación en porcentaje (null si no se conoce la capacidad).
            'occupancy_kg_pct' => $this->percent($this->score->occupancyKg),
            'occupancy_m3_pct' => $this->percent($this->score->occupancyM3),
        ];
    }

    private function percent(?float $ratio): ?float
    {
        return $ratio === null ? null : round($ratio * 100, 1);
    }
}
