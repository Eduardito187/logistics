<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Tipo de mantenimiento: preventivo (programado por plan), correctivo (reparación de una falla) o
 * inspección (revisión técnica). El dominio decide las transiciones; el enum solo enumera los tipos.
 */
enum MaintenanceType: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';
    case Inspection = 'inspection';

    /**
     * Texto en inglés para el admin y la API (regla de texto visible en inglés).
     */
    public function label(): string
    {
        return match ($this) {
            self::Preventive => 'Preventive',
            self::Corrective => 'Corrective',
            self::Inspection => 'Inspection',
        };
    }
}
