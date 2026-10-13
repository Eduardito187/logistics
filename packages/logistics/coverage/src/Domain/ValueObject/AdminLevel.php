<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

/**
 * Nivel dentro de la jerarquía geográfica:
 * Departamento > Provincia > Municipio > Ciudad > Zona > Microzona.
 *
 * Los niveles administrativos (Department..City) se modelan como GeoArea; Zone y Microzone son
 * geometrías de cobertura (polígonos PostGIS) que cuelgan de un área administrativa.
 */
enum AdminLevel: string
{
    case Department = 'department';
    case Province = 'province';
    case Municipality = 'municipality';
    case City = 'city';
    case Zone = 'zone';
    case Microzone = 'microzone';
}
