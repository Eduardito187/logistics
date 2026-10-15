<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Clase de servicio preventivo que cubre un plan de mantenimiento (cambio de aceite, llantas, frenos,
 * filtros, batería, general, inspección). Cada plan vigila un tipo de servicio por km y/o por días.
 */
enum ServiceKind: string
{
    case OilChange = 'oil_change';
    case Tires = 'tires';
    case Brakes = 'brakes';
    case Filters = 'filters';
    case Battery = 'battery';
    case General = 'general';
    case Inspection = 'inspection';

    /**
     * Texto en inglés para el admin y la API (regla de texto visible en inglés).
     */
    public function label(): string
    {
        return match ($this) {
            self::OilChange => 'Oil change',
            self::Tires => 'Tires',
            self::Brakes => 'Brakes',
            self::Filters => 'Filters',
            self::Battery => 'Battery',
            self::General => 'General',
            self::Inspection => 'Inspection',
        };
    }
}
