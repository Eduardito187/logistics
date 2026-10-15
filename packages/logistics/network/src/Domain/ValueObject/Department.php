<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

/**
 * Departamento de Bolivia en el que se asienta un nodo de la red.
 *
 * Son los nueve departamentos del país: una lista cerrada y estable. network solo ubica cada nodo en
 * su departamento; la jerarquía geográfica fina (provincia, municipio, ciudad, zona) vive en coverage.
 *
 * El departamento es el insumo con el que planning/promise agrupan corredores y camiones ("un camión =
 * un departamento"), por eso vive como value object propio del contexto y no como string suelto.
 */
enum Department: string
{
    case LaPaz = 'la_paz';

    case SantaCruz = 'santa_cruz';

    case Cochabamba = 'cochabamba';

    case Oruro = 'oruro';

    case Potosi = 'potosi';

    case Chuquisaca = 'chuquisaca';

    case Tarija = 'tarija';

    case Beni = 'beni';

    case Pando = 'pando';

    /**
     * Nombre legible para el admin y la consola. Son nombres propios: iguales en inglés y español.
     */
    public function label(): string
    {
        return match ($this) {
            self::LaPaz => 'La Paz',
            self::SantaCruz => 'Santa Cruz',
            self::Cochabamba => 'Cochabamba',
            self::Oruro => 'Oruro',
            self::Potosi => 'Potosí',
            self::Chuquisaca => 'Chuquisaca',
            self::Tarija => 'Tarija',
            self::Beni => 'Beni',
            self::Pando => 'Pando',
        };
    }
}
