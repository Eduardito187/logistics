<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;

/**
 * Decorador con cache (Valkey) del repositorio del mapa sku ↔ material SAP (frente escala).
 *
 * El mapa de materiales es un catálogo de referencia muy estable que se lista completo. Se cachea all()
 * con un TTL de red de seguridad y se invalida al guardar un mapeo.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por mapeo), nunca la entidad
 * serializada; al leer se rehidrata con MaterialMapping::reconstitute. findBySku/findByMaterial y la
 * escritura pasan intactas al repositorio real: solo all() se sirve desde cache.
 */
final readonly class CachingMaterialMappingRepository implements MaterialMappingRepository
{
    /** Catálogo muy estable: TTL largo; el guardado lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private MaterialMappingRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): MaterialMappingId
    {
        return $this->inner->nextIdentity();
    }

    public function save(MaterialMapping $mapping): void
    {
        $this->inner->save($mapping);
        $this->cache->forget(SapConnectorCacheKey::materialMappingsAll());
    }

    public function findBySku(string $sku): ?MaterialMapping
    {
        return $this->inner->findBySku($sku);
    }

    public function findByMaterial(SapMaterial $material): ?MaterialMapping
    {
        return $this->inner->findByMaterial($material);
    }

    /**
     * @return list<MaterialMapping>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(SapConnectorCacheKey::materialMappingsAll());
        if (is_array($cached)) {
            return array_map(self::fromPrimitive(...), $cached);
        }

        $mappings = $this->inner->all();
        $this->cache->put(
            SapConnectorCacheKey::materialMappingsAll(),
            array_map(self::toPrimitive(...), $mappings),
            self::TTL_SECONDS,
        );

        return $mappings;
    }

    /**
     * @return array{id: string, sku: string, material: string, center: string, warehouse: string, sellerId: string|null}
     */
    private static function toPrimitive(MaterialMapping $mapping): array
    {
        return [
            'id' => $mapping->id()->toString(),
            'sku' => $mapping->sku(),
            'material' => $mapping->material()->value,
            'center' => $mapping->center()->value,
            'warehouse' => $mapping->warehouse()->value,
            'sellerId' => $mapping->sellerId(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): MaterialMapping
    {
        $sellerId = $data['sellerId'] ?? null;

        return MaterialMapping::reconstitute(
            MaterialMappingId::fromString((string) $data['id']),
            (string) $data['sku'],
            SapMaterial::of((string) $data['material']),
            SapCenter::of((string) $data['center']),
            SapWarehouse::of((string) $data['warehouse']),
            is_string($sellerId) ? $sellerId : null,
        );
    }
}
