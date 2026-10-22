<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Infrastructure\Cache\CachingNodeRepository;
use Logistics\Network\Infrastructure\Cache\NetworkCacheKey;

/**
 * Repositorio en memoria que cuenta los all()/save() para aislar el decorador de la base.
 */
function fakeNodeRepository(Node ...$nodes): NodeRepository
{
    return new class(array_values($nodes)) implements NodeRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        /** @param list<Node> $nodes */
        public function __construct(private array $nodes) {}

        public function nextIdentity(): NodeId
        {
            return NodeId::generate();
        }

        public function save(Node $node): void
        {
            $this->saves++;
            $this->nodes[] = $node;
        }

        public function findById(NodeId $id): ?Node
        {
            return null;
        }

        public function findByCode(NodeCode $code): ?Node
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->nodes;
        }
    };
}

function sampleNode(string $code = 'CD-01'): Node
{
    return Node::fromState(
        NodeId::generate(),
        NodeCode::fromString($code),
        'Centro Santa Cruz',
        NodeType::DistributionCenter,
        GeoPoint::of(-17.78, -63.18),
        NodeStatus::Active,
        Department::SantaCruz,
    );
}

it('serves the second all() from cache without hitting the inner repository', function (): void {
    $inner = fakeNodeRepository(sampleNode('CD-01'), sampleNode('ST-02'));
    $repo = new CachingNodeRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(Node::class)
        ->and($second[0]->code()->toString())->toBe('CD-01')
        ->and($second[0]->type())->toBe(NodeType::DistributionCenter)
        ->and($second[0]->department())->toBe(Department::SantaCruz);
});

it('stores the primitive array of each node, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingNodeRepository(fakeNodeRepository(sampleNode('CD-01')), $cache);

    $repo->all();

    $stored = $cache->get(NetworkCacheKey::nodesAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['code'])->toBe('CD-01')
        ->and($stored[0]['type'])->toBe('distribution_center')
        ->and($stored[0]['status'])->toBe('active')
        ->and($stored[0]['department'])->toBe('santa_cruz');
});

it('invalidates the cached list on save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $inner = fakeNodeRepository(sampleNode('CD-01'));
    $repo = new CachingNodeRepository($inner, $cache);

    $repo->all();
    expect($cache->has(NetworkCacheKey::nodesAll()))->toBeTrue();

    $repo->save(sampleNode('ST-02'));
    expect($cache->has(NetworkCacheKey::nodesAll()))->toBeFalse()
        ->and($inner->saves)->toBe(1);

    // La siguiente lectura vuelve a tocar la base y refleja el nuevo nodo.
    expect($repo->all())->toHaveCount(2)
        ->and($inner->allCalls)->toBe(2);
});
