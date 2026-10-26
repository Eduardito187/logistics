<?php

declare(strict_types=1);

namespace Logistics\Settings\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Decorador con cache (Valkey) del handler que lee el valor vigente de una clave de configuración
 * (frente escala).
 *
 * La configuración versionada (reglas de zona, cascadas de notificación, overrides de integración) es
 * un dato de referencia muy leído y que cambia poco. Se cachea SOLO la lectura "a ahora" —la consulta
 * caliente—, con un TTL de red de seguridad corto; las lecturas con fecha histórica (`at` presente) pasan
 * directo al handler real, porque el pasado es inmutable pero no es la lectura caliente y no conviene
 * poblar la cache con cada fecha consultada.
 *
 * Respeta la regla #7: el valor ya es la forma primitiva (array<string, mixed>); se cachea tal cual y se
 * devuelve sin rehidratar ningún objeto de dominio. El caso "no existe" (null) no se memoiza.
 *
 * Invalidación por evento: la entrada se olvida en CacheInvalidatingSettingsRepository::add(), es decir
 * al publicar una versión nueva de la clave. El TTL es la red de seguridad (cubre, por ejemplo, una
 * versión programada para entrar en vigencia en el futuro).
 *
 * @implements QueryHandler<GetSettingQuery, array<string, mixed>|null>
 */
final readonly class CachingGetSettingHandler implements QueryHandler
{
    /** Configuración editable: TTL de red de seguridad corto; el publicar una versión la invalida antes. */
    private const int TTL_SECONDS = 600;

    /**
     * @param QueryHandler<GetSettingQuery, array<string, mixed>|null> $inner
     */
    public function __construct(
        private QueryHandler $inner,
        private CacheRepository $cache,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(Query $query): ?array
    {
        if (!$query instanceof GetSettingQuery) {
            throw SettingsException::because('CachingGetSettingHandler solo maneja GetSettingQuery.');
        }

        // Solo la lectura "a ahora" se cachea; una fecha explícita va directo al handler real.
        if ($query->at !== null && $query->at !== '') {
            return $this->inner->handle($query);
        }

        $cacheKey = SettingsCacheKey::currentValue($query->key);

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $value = $this->inner->handle($query);
        if (is_array($value)) {
            $this->cache->put($cacheKey, $value, self::TTL_SECONDS);
        }

        return $value;
    }
}
