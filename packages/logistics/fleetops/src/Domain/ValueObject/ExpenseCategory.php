<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

/**
 * Categoría de un gasto de flota que no es combustible ni mantenimiento (esos tienen su propio contexto).
 * Sirve para el TCO: peajes, multas, llantas, repuestos sueltos, lavado, parqueo, seguro y otros.
 */
enum ExpenseCategory: string
{
    case Toll = 'toll';
    case Fine = 'fine';
    case Tires = 'tires';
    case Parts = 'parts';
    case Wash = 'wash';
    case Parking = 'parking';
    case Insurance = 'insurance';
    case Other = 'other';

    /**
     * Texto en inglés para el admin y la API (regla de texto visible en inglés).
     */
    public function label(): string
    {
        return match ($this) {
            self::Toll => 'Toll',
            self::Fine => 'Fine',
            self::Tires => 'Tires',
            self::Parts => 'Parts',
            self::Wash => 'Wash',
            self::Parking => 'Parking',
            self::Insurance => 'Insurance',
            self::Other => 'Other',
        };
    }
}
