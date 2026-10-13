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

    /**
     * Profundidad del nivel en la jerarquía: 0 = Departamento (el más amplio) … 5 = Microzona (el más
     * específico). Permite ordenar y comparar niveles sin repetir el orden en varios sitios.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Department => 0,
            self::Province => 1,
            self::Municipality => 2,
            self::City => 3,
            self::Zone => 4,
            self::Microzone => 5,
        };
    }

    /**
     * Nivel padre inmediato en la cadena Departamento > Provincia > Municipio > Ciudad > Zona >
     * Microzona. El Departamento es la raíz y no tiene padre (null).
     */
    public function parentLevel(): ?self
    {
        return match ($this) {
            self::Department => null,
            self::Province => self::Department,
            self::Municipality => self::Province,
            self::City => self::Municipality,
            self::Zone => self::City,
            self::Microzone => self::Zone,
        };
    }

    /**
     * ¿Es un nivel administrativo (Departamento..Ciudad)? Esos niveles viven como GeoArea. Zona y
     * Microzona son geometrías de cobertura (polígonos PostGIS), no áreas administrativas.
     */
    public function isAdministrative(): bool
    {
        return $this->rank() <= self::City->rank();
    }

    /**
     * ¿Es una geometría de cobertura (Zona o Microzona)?
     */
    public function isCoverageGeometry(): bool
    {
        return !$this->isAdministrative();
    }

    /**
     * ¿Puede este nivel contener directa o indirectamente a $child? Un nivel contiene a otro más
     * específico (mayor rank); nunca a sí mismo ni a uno más amplio.
     */
    public function contains(self $child): bool
    {
        return $this->rank() < $child->rank();
    }

    /**
     * Niveles administrativos, de más amplio a más específico (Departamento..Ciudad).
     *
     * @return array<int, self>
     */
    public static function administrative(): array
    {
        return [self::Department, self::Province, self::Municipality, self::City];
    }
}
