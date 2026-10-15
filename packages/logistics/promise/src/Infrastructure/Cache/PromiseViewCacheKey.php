<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Cache;

/**
 * Clave de cache del modelo de lectura de una promesa por id (frente E — escala).
 *
 * Vive en un solo lugar porque dos piezas la comparten: el handler de lectura que la puebla
 * (CachingGetPromiseHandler) y el repositorio que la invalida al guardar la promesa
 * (CacheInvalidatingPromiseRepository). Así la convención no se duplica ni se desincroniza.
 */
final class PromiseViewCacheKey
{
    private const string PREFIX = 'logistics:promise:view:';

    public static function forId(string $promiseId): string
    {
        return self::PREFIX.$promiseId;
    }
}
