<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Decorador con cache (Valkey) del repositorio de zonas (frente escala).
 *
 * Las zonas de cobertura son un catálogo chico y muy estable que se lista completo (admin, resolución de
 * reglas). Se cachea all() con un TTL de red de seguridad y se invalida al guardar una zona.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por zona, con el anillo del
 * polígono y las reglas ya en array), nunca el objeto de dominio serializado; al leer se rehidrata con
 * Zone::reconstitute. findById/findByNameAndParent, la escritura y la resolución espacial
 * (findZoneContaining, que la resuelve PostGIS) pasan intactas al repositorio real: solo all() se
 * sirve desde cache.
 */
final readonly class CachingZoneRepository implements ZoneRepository
{
    /** Catálogo muy estable: TTL largo; el evento de escritura lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private ZoneRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): ZoneId
    {
        return $this->inner->nextIdentity();
    }

    public function save(Zone $zone): void
    {
        $this->inner->save($zone);
        $this->cache->forget(CoverageCacheKey::zonesAll());
    }

    public function findById(ZoneId $id): ?Zone
    {
        return $this->inner->findById($id);
    }

    public function findByNameAndParent(string $name, ?GeoAreaId $parentAreaId): ?Zone
    {
        return $this->inner->findByNameAndParent($name, $parentAreaId);
    }

    /**
     * @return array<int, Zone>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(CoverageCacheKey::zonesAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): Zone => self::fromPrimitive($data), $cached);
        }

        $zones = $this->inner->all();
        $this->cache->put(
            CoverageCacheKey::zonesAll(),
            array_map(static fn (Zone $zone): array => self::toPrimitive($zone), array_values($zones)),
            self::TTL_SECONDS,
        );

        return $zones;
    }

    public function findZoneContaining(GeoPoint $point): ?Zone
    {
        return $this->inner->findZoneContaining($point);
    }

    /**
     * @return array{id: string, name: string, parentAreaId: string|null, ring: array<int, array{0: float, 1: float}>, rules: array<string, mixed>, active: bool}
     */
    private static function toPrimitive(Zone $zone): array
    {
        return [
            'id' => $zone->id()->toString(),
            'name' => $zone->name(),
            'parentAreaId' => $zone->parentAreaId()?->toString(),
            'ring' => $zone->polygon()->ring,
            'rules' => $zone->rules()->toArray(),
            'active' => $zone->isActive(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): Zone
    {
        $parentAreaId = $data['parentAreaId'] ?? null;

        /** @var array<int, array{0: float|int, 1: float|int}> $ring */
        $ring = $data['ring'];
        /** @var array<string, mixed> $rules */
        $rules = $data['rules'];

        return Zone::reconstitute(
            ZoneId::fromString((string) $data['id']),
            (string) $data['name'],
            is_string($parentAreaId) ? GeoAreaId::fromString($parentAreaId) : null,
            Polygon::fromRing($ring),
            ZoneRules::fromArray($rules),
            (bool) $data['active'],
        );
    }
}
