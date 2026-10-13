<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Tipo de alerta de mal uso detectada sobre la telemetría de un vehículo.
 *
 * El dominio solo enumera los tipos válidos; quién los levanta es el MisuseAnalyzer (análisis puro)
 * y el orquestador de ingesta (Grupo A), que materializa la alerta como agregado TelemetryAlert.
 */
enum AlertType: string
{
    /** Movimiento fuera del horario laboral configurado. */
    case AfterHoursMovement = 'after_hours_movement';

    /** Inactividad prolongada con motor encendido (posible descanso no autorizado o mal uso). */
    case ExcessiveIdle = 'excessive_idle';

    /** Incursión en una zona prohibida (geocerca ForbiddenZone). */
    case GeofenceViolation = 'geofence_violation';

    /** Uso del vehículo sin una ruta asignada activa. */
    case UnauthorizedUse = 'unauthorized_use';

    /** Exceso de velocidad por sobre el límite configurado. */
    case Speeding = 'speeding';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::AfterHoursMovement => 'After-hours movement',
            self::ExcessiveIdle => 'Excessive idle',
            self::GeofenceViolation => 'Geofence violation',
            self::UnauthorizedUse => 'Unauthorized use',
            self::Speeding => 'Speeding',
        };
    }
}
