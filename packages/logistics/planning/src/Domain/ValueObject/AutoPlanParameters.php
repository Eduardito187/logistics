<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Parámetros CONFIGURABLES del auto-planificador para una corrida. Salen de la configuración versionada
 * (settings), con posible override puntual por comando/API. Son datos puros: el motor de empaque y el
 * runner los consumen sin conocer de dónde salieron.
 *
 * - strategy: el modo (optimization | slots).
 * - departAt: hora de salida del depósito en ISO-8601; de ahí se calculan las ETA.
 * - defaultWeightKgPerPackage / defaultVolumeM3PerPackage: peso y volumen asumidos por bulto cuando el
 *   envío no trae esos datos; permiten estimar la carga para el bin-packing.
 * - capacitySlackRatio: holgura (0..1) que se reserva de la capacidad del camión (p. ej. 0.1 = usar
 *   solo el 90 % del kg/m3 nominal) para no planificar al límite.
 * - originNodeId: nodo CD de origen de las rutas (opcional; informativo para el plan).
 */
final readonly class AutoPlanParameters
{
    public function __construct(
        public PlanStrategy $strategy,
        public string $departAt,
        public float $defaultWeightKgPerPackage,
        public float $defaultVolumeM3PerPackage,
        public float $capacitySlackRatio,
        public ?string $originNodeId = null,
    ) {
        if ($defaultWeightKgPerPackage <= 0.0) {
            throw PlanningException::because('el peso por producto por defecto debe ser mayor que cero');
        }
        if ($defaultVolumeM3PerPackage <= 0.0) {
            throw PlanningException::because('el volumen por producto por defecto debe ser mayor que cero');
        }
        if ($capacitySlackRatio < 0.0 || $capacitySlackRatio >= 1.0) {
            throw PlanningException::because('la holgura de capacidad debe estar en [0, 1)');
        }
        if (trim($departAt) === '') {
            throw PlanningException::because('la hora de salida no puede estar vacía');
        }
    }

    /**
     * Fracción utilizable de la capacidad nominal tras descontar la holgura (p. ej. 0.9 con 10 %).
     */
    public function usableCapacityRatio(): float
    {
        return 1.0 - $this->capacitySlackRatio;
    }
}
