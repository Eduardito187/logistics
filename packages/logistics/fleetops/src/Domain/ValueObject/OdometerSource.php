<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Origen de una lectura de odómetro. El mismo vehículo acumula lecturas de fuentes distintas: carga de
 * combustible, orden de mantenimiento, salida/retorno, telemetría o captura manual.
 */
enum OdometerSource: string
{
    /** Captura manual (operador, planilla). */
    case Manual = 'manual';

    /** Derivada de la telemetría del vehículo (odómetro del dispositivo). */
    case Telemetry = 'telemetry';

    /** Tomada al cargar combustible. */
    case Refuel = 'refuel';

    /** Tomada en una orden de mantenimiento. */
    case Maintenance = 'maintenance';

    /** Tomada al registrar/cerrar una salida. */
    case Departure = 'departure';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Telemetry => 'Telemetry',
            self::Refuel => 'Refuel',
            self::Maintenance => 'Maintenance',
            self::Departure => 'Departure',
        };
    }
}
