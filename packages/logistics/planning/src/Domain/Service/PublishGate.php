<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\PublishDecision;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;

/**
 * PUERTA DE PUBLICACIÓN automática (Fase 3.2, R3.4): decide si un plan PROPUESTO puede publicarse sin
 * intervención, o si se retiene para que el planificador lo revise. Servicio de dominio PURO: recibe los
 * hechos ya resueltos (estado, paradas, ocupación, aptitud del vehículo) y los umbrales; no hace I/O ni
 * conoce otros contextos. Así es trivialmente testeable y determinista.
 *
 * La decisión es conservadora: cualquier motivo que no se cumpla RETIENE el plan (ADR 012: el auto-planner
 * asiste, no reemplaza al humano). Acumula TODOS los motivos para que la alerta sea accionable de una vez.
 */
final readonly class PublishGate
{
    /**
     * Evalúa un plan contra los umbrales. La ocupación (ratio carga/capacidad por kg y por m3) llega null
     * cuando no se conoce la capacidad del camión: en ese caso no se puede verificar y no retiene por eso.
     */
    public function evaluate(
        PlanStatus $status,
        int $stopCount,
        ?float $occupancyKg,
        ?float $occupancyM3,
        bool $vehicleAvailable,
        PublishThresholds $thresholds,
    ): PublishDecision {
        $reasons = [];

        if ($status !== PlanStatus::Proposed) {
            $reasons[] = "el plan no está propuesto (estado actual: {$status->value})";
        }

        if ($stopCount <= 0) {
            $reasons[] = 'el plan no tiene paradas asignadas';
        }

        if ($thresholds->requireVehicleAvailable && !$vehicleAvailable) {
            $reasons[] = 'el vehículo del plan ya no está disponible o apto para la fecha';
        }

        $occupancy = $this->peakOccupancy($occupancyKg, $occupancyM3);
        if ($occupancy !== null) {
            if ($thresholds->maxOccupancy > 0.0 && $occupancy > $thresholds->maxOccupancy) {
                $reasons[] = sprintf(
                    'el camión está sobrecargado (ocupación %.0f %% supera el máximo %.0f %%)',
                    $occupancy * 100,
                    $thresholds->maxOccupancy * 100,
                );
            }
            if ($thresholds->minOccupancy > 0.0 && $occupancy < $thresholds->minOccupancy) {
                $reasons[] = sprintf(
                    'el camión va por debajo de la ocupación mínima (%.0f %% < %.0f %%)',
                    $occupancy * 100,
                    $thresholds->minOccupancy * 100,
                );
            }
        }

        return $reasons === [] ? PublishDecision::publish() : PublishDecision::hold($reasons);
    }

    /**
     * Ocupación de referencia del camión: la MAYOR entre la de kg y la de m3 (la dimensión que más llena el
     * camión manda). Null si no se conoce ninguna.
     */
    private function peakOccupancy(?float $occupancyKg, ?float $occupancyM3): ?float
    {
        $values = array_values(array_filter(
            [$occupancyKg, $occupancyM3],
            static fn (?float $value): bool => $value !== null,
        ));

        if ($values === []) {
            return null;
        }

        return max($values);
    }
}
