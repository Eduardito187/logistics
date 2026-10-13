<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Tipo de geocerca: para qué sirve el polígono. El tipo decide la semántica de una incursión:
 * entrar a una ForbiddenZone es una violación; estar dentro de un DistributionCenter es normal.
 */
enum GeofenceType: string
{
    /** Centro de distribución propio. */
    case DistributionCenter = 'distribution_center';

    /** Tienda / sucursal. */
    case Store = 'store';

    /** Zona autorizada de circulación. */
    case AuthorizedZone = 'authorized_zone';

    /** Zona prohibida: entrar dispara una alerta de violación. */
    case ForbiddenZone = 'forbidden_zone';

    /** Área de descanso autorizada para el chofer. */
    case RestArea = 'rest_area';

    /** Estación de combustible. */
    case FuelStation = 'fuel_station';

    /** Domicilio / punto de un cliente. */
    case Customer = 'customer';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::DistributionCenter => 'Distribution center',
            self::Store => 'Store',
            self::AuthorizedZone => 'Authorized zone',
            self::ForbiddenZone => 'Forbidden zone',
            self::RestArea => 'Rest area',
            self::FuelStation => 'Fuel station',
            self::Customer => 'Customer',
        };
    }
}
