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
     * Departamento a partir de un texto escrito de cualquier forma razonable: el código (`santa_cruz`), el
     * nombre legible (`Santa Cruz`, `Potosí`) o variantes de caja y separadores. Null si no corresponde a
     * ninguno. Es la puerta única para leer valores viejos o cargados a mano sin romper.
     */
    public static function tryFromText(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $slug = strtr(mb_strtolower(trim($value)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $slug = preg_replace('/[\s\-]+/', '_', $slug) ?? $slug;

        return self::tryFrom($slug);
    }

    /**
     * Códigos de los nueve departamentos, en el orden del catálogo.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $department): string => $department->value, self::cases());
    }

    /**
     * Nombre legible para el admin, la consola y la app. Son nombres propios: iguales en inglés y español.
     * Es el único mapa código → nombre de la plataforma.
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
