<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Tipo de combustible de un vehículo / una carga. Valores de Bolivia: gasolina especial y premium,
 * diésel y GNV (gas natural vehicular). Compartido por combustible (precios/cargas) y el perfil del vehículo.
 */
enum FuelType: string
{
    case GasolineSpecial = 'gasoline_special';
    case GasolinePremium = 'gasoline_premium';
    case Diesel = 'diesel';
    case Gnv = 'gnv';

    /**
     * Texto en inglés para el admin y la API (regla de texto visible en inglés).
     */
    public function label(): string
    {
        return match ($this) {
            self::GasolineSpecial => 'Special gasoline',
            self::GasolinePremium => 'Premium gasoline',
            self::Diesel => 'Diesel',
            self::Gnv => 'CNG',
        };
    }
}
