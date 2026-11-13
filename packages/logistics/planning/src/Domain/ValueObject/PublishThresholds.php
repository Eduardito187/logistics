<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Umbrales de la PUERTA DE PUBLICACIÓN automática (PublishGate, Fase 3.2): bajo qué condiciones un plan
 * PROPUESTO puede publicarse sin intervención humana. Dato puro e inmutable derivado de la configuración
 * (sección «Planning › Automation»), para que la decisión sea testeable sin leer config dentro del dominio.
 *
 * - `maxOccupancy`: ocupación máxima admitida (ratio carga/capacidad, 1.0 = 100 %); por encima el camión
 *   está sobrecargado y el plan se RETIENE para revisión. 0 o menos desactiva la verificación.
 * - `minOccupancy`: ocupación mínima para publicar (ratio); por debajo el plan se RETIENE (camión casi
 *   vacío, probablemente conviene consolidar). 0 lo desactiva (comportamiento por defecto).
 * - `requireVehicleAvailable`: exige que el vehículo del plan siga DISPONIBLE/APTO para la fecha; si no,
 *   el plan se retiene (el camión salió de servicio, venció un documento, etc.).
 */
final readonly class PublishThresholds
{
    private function __construct(
        public float $maxOccupancy,
        public float $minOccupancy,
        public bool $requireVehicleAvailable,
    ) {}

    /**
     * Construye los umbrales validando los rangos. La ocupación mínima no puede superar a la máxima cuando
     * ambas están activas (sería imposible publicar nada).
     */
    public static function of(
        float $maxOccupancy,
        float $minOccupancy,
        bool $requireVehicleAvailable,
    ): self {
        if ($minOccupancy < 0.0) {
            throw PlanningException::because('la ocupación mínima no puede ser negativa');
        }
        if ($maxOccupancy > 0.0 && $minOccupancy > 0.0 && $minOccupancy > $maxOccupancy) {
            throw PlanningException::because('la ocupación mínima no puede superar a la máxima');
        }

        return new self($maxOccupancy, $minOccupancy, $requireVehicleAvailable);
    }
}
