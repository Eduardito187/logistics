<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Cache;

/**
 * Claves de cache (Valkey) de los catálogos de red: nodos y tramos (frente escala).
 *
 * Centraliza la convención para que la pueblen los repositorios cacheantes y la invaliden los mismos
 * repositorios al guardar/borrar. El prefijo lleva el nombre del contexto para que no colisione con las
 * claves de otros bounded contexts.
 */
final class NetworkCacheKey
{
    private const string PREFIX = 'logistics:network:';

    /** Lista completa de nodos (EloquentNodeRepository::all()). */
    public static function nodesAll(): string
    {
        return self::PREFIX.'nodes:all';
    }

    /** Lista completa de tramos/corredores (EloquentLegRepository::all()). */
    public static function legsAll(): string
    {
        return self::PREFIX.'legs:all';
    }
}
