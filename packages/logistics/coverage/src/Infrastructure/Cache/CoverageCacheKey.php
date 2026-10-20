<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Cache;

/**
 * Claves de cache (Valkey) de los catálogos de cobertura: zonas, jerarquía administrativa y municipios
 * (frente escala).
 *
 * Centraliza la convención para que la pueblen los repositorios/catálogos cacheantes y la invaliden al
 * guardar. El prefijo lleva el nombre del contexto para que no colisione con otros bounded contexts.
 */
final class CoverageCacheKey
{
    private const string PREFIX = 'logistics:coverage:';

    /** Lista completa de zonas (EloquentZoneRepository::all()). */
    public static function zonesAll(): string
    {
        return self::PREFIX.'zones:all';
    }

    /** Lista completa de la jerarquía administrativa (EloquentGeoAreaRepository::all()). */
    public static function geoAreasAll(): string
    {
        return self::PREFIX.'geo_areas:all';
    }

    /** Catálogo completo de municipios resueltos con su departamento (EloquentMunicipalityCatalog::all()). */
    public static function municipalitiesAll(): string
    {
        return self::PREFIX.'municipalities:all';
    }
}
