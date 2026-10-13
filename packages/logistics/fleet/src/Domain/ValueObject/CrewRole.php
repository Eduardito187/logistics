<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Rol de una persona dentro de una tripulación.
 */
enum CrewRole: string
{
    /** Conduce el vehículo; requiere licencia vigente. */
    case Driver = 'driver';

    /** Acompaña en la carga, descarga y entrega. */
    case Helper = 'helper';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Driver => 'Driver',
            self::Helper => 'Helper',
        };
    }
}
