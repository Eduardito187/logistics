<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * A quién pertenece un documento de flota: un vehículo, un chofer o un ayudante.
 *
 * El id del dueño se guarda como texto (referencia por valor al agregado correspondiente) para no
 * acoplar los documentos a la identidad concreta de cada entidad.
 */
enum DocumentOwnerType: string
{
    case Vehicle = 'vehicle';

    case Driver = 'driver';

    case Helper = 'helper';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Vehicle',
            self::Driver => 'Driver',
            self::Helper => 'Helper',
        };
    }
}
