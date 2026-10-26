<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Cache;

/**
 * Clave de cache (Valkey) del valor vigente de una clave de configuración (frente escala).
 *
 * La comparten el handler de lectura que la puebla (CachingGetSettingHandler) y el repositorio que la
 * invalida al agregar una versión nueva (CacheInvalidatingSettingsRepository). El prefijo lleva el
 * nombre del contexto para que no colisione con otros bounded contexts.
 */
final class SettingsCacheKey
{
    private const string PREFIX = 'logistics:settings:value:';

    /**
     * Clave del valor vigente "a ahora" de una clave de configuración. Solo se cachea la lectura a la
     * fecha actual (la consulta caliente); las lecturas con fecha histórica no se cachean.
     */
    public static function currentValue(string $key): string
    {
        return self::PREFIX.$key;
    }

    /**
     * Clave del valor vigente de una integración tal como lo lee IntegrationConfig en el boot de cada
     * request. A diferencia de currentValue(), guarda un sobre `{value: array}` que incluye el caso "no
     * configurado" ([]), para que una integración nunca configurada tampoco vaya a la base en cada
     * request. Se invalida junto con currentValue() al publicar una versión nueva.
     */
    public static function integrationValue(string $key): string
    {
        return self::PREFIX.'integration:'.$key;
    }
}
