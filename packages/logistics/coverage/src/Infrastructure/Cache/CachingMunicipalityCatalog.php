<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;

/**
 * Decorador con cache (Valkey) del catálogo de municipios (frente escala).
 *
 * El catálogo resuelve, con un CTE recursivo, el departamento de cada municipio: es una consulta cara
 * sobre datos muy estables que alimenta selects del admin y a otros contextos (capacity). Se cachea
 * all() con un TTL de red de seguridad.
 *
 * El catálogo es de solo lectura (no tiene escritura propia): la invalidación por evento la dispara
 * CachingGeoAreaRepository::save(), que olvida esta clave cada vez que cambia la jerarquía (los
 * municipios son áreas de esa misma tabla).
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array por municipio), nunca el ReadModel
 * serializado; al leer se reconstruye el MunicipalityView desde sus campos planos.
 */
final readonly class CachingMunicipalityCatalog implements MunicipalityCatalog
{
    /** Catálogo muy estable: TTL largo; el cambio en la jerarquía lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private MunicipalityCatalog $inner,
        private CacheRepository $cache,
    ) {}

    /**
     * @return array<int, MunicipalityView>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(CoverageCacheKey::municipalitiesAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): MunicipalityView => self::fromPrimitive($data), $cached);
        }

        $views = $this->inner->all();
        $this->cache->put(
            CoverageCacheKey::municipalitiesAll(),
            array_map(static fn (MunicipalityView $view): array => $view->toArray(), array_values($views)),
            self::TTL_SECONDS,
        );

        return $views;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): MunicipalityView
    {
        $departmentId = $data['departmentId'] ?? null;
        $departmentName = $data['departmentName'] ?? null;

        return new MunicipalityView(
            (string) $data['id'],
            (string) $data['name'],
            is_string($departmentId) ? $departmentId : null,
            is_string($departmentName) ? $departmentName : null,
        );
    }
}
