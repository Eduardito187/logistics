<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Estado operativo de un vehículo dentro de la flota.
 *
 * Es el dominio quien decide las transiciones (ver Vehicle); el enum solo enumera los estados válidos.
 */
enum VehicleStatus: string
{
    /** Disponible para armar tripulación y salir a ruta. */
    case Available = 'available';

    /** En ruta o asignado a una operación en curso. */
    case InService = 'in_service';

    /** Fuera de servicio por mantenimiento o reparación. */
    case Maintenance = 'maintenance';

    /** Dado de baja: no se asigna ni aparece en la planificación. */
    case Inactive = 'inactive';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::InService => 'In service',
            self::Maintenance => 'Maintenance',
            self::Inactive => 'Inactive',
        };
    }
}
