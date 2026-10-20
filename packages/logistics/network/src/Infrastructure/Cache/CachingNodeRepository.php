<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Cache;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Decorador con cache (Valkey) del repositorio de nodos (frente escala).
 *
 * Los nodos son datos de referencia que cambian poco pero se leen mucho (listados, cálculo de
 * corredores por departamento que consulta promise/planning). Por eso se cachea la lista completa
 * (all()) con un TTL de red de seguridad y se invalida al guardar o desactivar un nodo.
 *
 * Respeta la regla #7: en cache vive la FORMA PRIMITIVA (array de escalares por nodo), nunca el objeto
 * de dominio serializado; al leer se rehidrata con Node::fromState. Las lecturas puntuales (findById,
 * findByCode) y la escritura pasan intactas al repositorio real: solo all() se sirve desde cache.
 */
final readonly class CachingNodeRepository implements NodeRepository
{
    /** Catálogo muy estable: TTL largo; el evento de escritura lo invalida antes de que caduque. */
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private NodeRepository $inner,
        private CacheRepository $cache,
    ) {}

    public function nextIdentity(): NodeId
    {
        return $this->inner->nextIdentity();
    }

    public function save(Node $node): void
    {
        $this->inner->save($node);
        $this->cache->forget(NetworkCacheKey::nodesAll());
    }

    public function findById(NodeId $id): ?Node
    {
        return $this->inner->findById($id);
    }

    public function findByCode(NodeCode $code): ?Node
    {
        return $this->inner->findByCode($code);
    }

    /**
     * @return list<Node>
     */
    public function all(): array
    {
        /** @var list<array<string, mixed>>|null $cached */
        $cached = $this->cache->get(NetworkCacheKey::nodesAll());
        if (is_array($cached)) {
            return array_map(static fn (array $data): Node => self::fromPrimitive($data), $cached);
        }

        $nodes = $this->inner->all();
        $this->cache->put(
            NetworkCacheKey::nodesAll(),
            array_map(static fn (Node $node): array => self::toPrimitive($node), $nodes),
            self::TTL_SECONDS,
        );

        return $nodes;
    }

    /**
     * @return array{id: string, code: string, name: string, type: string, lat: float, lng: float, status: string, department: string|null}
     */
    private static function toPrimitive(Node $node): array
    {
        return [
            'id' => $node->id()->toString(),
            'code' => $node->code()->toString(),
            'name' => $node->name(),
            'type' => $node->type()->value,
            'lat' => $node->location()->latitude,
            'lng' => $node->location()->longitude,
            'status' => $node->status()->value,
            'department' => $node->department()?->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fromPrimitive(array $data): Node
    {
        $department = $data['department'] ?? null;

        return Node::fromState(
            NodeId::fromString((string) $data['id']),
            NodeCode::fromString((string) $data['code']),
            (string) $data['name'],
            NodeType::from((string) $data['type']),
            GeoPoint::of((float) $data['lat'], (float) $data['lng']),
            NodeStatus::from((string) $data['status']),
            is_string($department) ? Department::from($department) : null,
        );
    }
}
