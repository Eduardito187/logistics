<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;

/**
 * Decorador con cache (Valkey) del repositorio de tramos/corredores (frente escala).
 *
 * Los tramos son datos de referencia que cambian poco y se recorren completos al calcular los
 * corredores operativos por departamento (insumo de promise/planning). Por eso se cachea la lista
 * completa (all()) con un TTL de red de seguridad y se invalida al definir o remover un tramo.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por tramo), nunca el objeto
 * de dominio serializado; al leer se rehidrata con Leg::fromState. findById/findByEndpoints y las
 * escrituras pasan intactas al repositorio real: solo all() se sirve desde cache.
 */
final readonly class CachingLegRepository implements LegRepository
{
    /** Catálogo muy estable: TTL largo; el evento de escritura lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private LegRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): LegId
    {
        return $this->inner->nextIdentity();
    }

    public function save(Leg $leg): void
    {
        $this->inner->save($leg);
        $this->cache->forget(NetworkCacheKey::legsAll());
    }

    public function remove(Leg $leg): void
    {
        $this->inner->remove($leg);
        $this->cache->forget(NetworkCacheKey::legsAll());
    }

    public function findById(LegId $id): ?Leg
    {
        return $this->inner->findById($id);
    }

    public function findByEndpoints(NodeId $from, NodeId $to, CorridorType $corridor): ?Leg
    {
        return $this->inner->findByEndpoints($from, $to, $corridor);
    }

    /**
     * @return list<Leg>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(NetworkCacheKey::legsAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): Leg => self::fromPrimitive($data), $cached);
        }

        $legs = $this->inner->all();
        $this->cache->put(
            NetworkCacheKey::legsAll(),
            array_map(static fn (Leg $leg): array => self::toPrimitive($leg), $legs),
            self::TTL_SECONDS,
        );

        return $legs;
    }

    /**
     * @return array{id: string, from: string, to: string, corridor: string}
     */
    private static function toPrimitive(Leg $leg): array
    {
        return [
            'id' => $leg->id()->toString(),
            'from' => $leg->from()->toString(),
            'to' => $leg->to()->toString(),
            'corridor' => $leg->corridor()->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): Leg
    {
        return Leg::fromState(
            LegId::fromString((string) $data['id']),
            NodeId::fromString((string) $data['from']),
            NodeId::fromString((string) $data['to']),
            CorridorType::from((string) $data['corridor']),
        );
    }
}
