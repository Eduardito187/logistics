<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;

/**
 * Decorador con cache (Valkey) del repositorio de la jerarquía administrativa (frente escala).
 *
 * La jerarquía (departamentos, provincias, municipios, ciudades) es un catálogo estable que se recorre
 * completo. Se cachea all() con un TTL de red de seguridad y se invalida al guardar un área.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por área), nunca el objeto
 * de dominio serializado; al leer se rehidrata con GeoArea::create. findById/findByNameAndLevel y la
 * escritura pasan intactas al repositorio real: solo all() se sirve desde cache.
 */
final readonly class CachingGeoAreaRepository implements GeoAreaRepository
{
    /** Catálogo muy estable: TTL largo; el evento de escritura lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private GeoAreaRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): GeoAreaId
    {
        return $this->inner->nextIdentity();
    }

    public function save(GeoArea $area): void
    {
        $this->inner->save($area);
        $this->cache->forget(CoverageCacheKey::geoAreasAll());
        // La vista de municipios se deriva de la misma tabla (CTE recursivo): también se invalida.
        $this->cache->forget(CoverageCacheKey::municipalitiesAll());
    }

    public function findById(GeoAreaId $id): ?GeoArea
    {
        return $this->inner->findById($id);
    }

    public function findByNameAndLevel(string $name, AdminLevel $level): ?GeoArea
    {
        return $this->inner->findByNameAndLevel($name, $level);
    }

    /**
     * @return array<int, GeoArea>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(CoverageCacheKey::geoAreasAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): GeoArea => self::fromPrimitive($data), $cached);
        }

        $areas = $this->inner->all();
        $this->cache->put(
            CoverageCacheKey::geoAreasAll(),
            array_map(static fn (GeoArea $area): array => self::toPrimitive($area), array_values($areas)),
            self::TTL_SECONDS,
        );

        return $areas;
    }

    /**
     * @return array{id: string, name: string, level: string, parentId: string|null}
     */
    private static function toPrimitive(GeoArea $area): array
    {
        return [
            'id' => $area->id()->toString(),
            'name' => $area->name(),
            'level' => $area->level()->value,
            'parentId' => $area->parentId()?->toString(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): GeoArea
    {
        $parentId = $data['parentId'] ?? null;

        return GeoArea::create(
            GeoAreaId::fromString((string) $data['id']),
            (string) $data['name'],
            AdminLevel::from((string) $data['level']),
            is_string($parentId) ? GeoAreaId::fromString($parentId) : null,
        );
    }
}
