<?php

declare(strict_types=1);

use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeHandler;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\DefineLeg\DefineLegHandler;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeHandler;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockCommand;
use Logistics\Network\Application\Command\RegisterReceivingDock\RegisterReceivingDockHandler;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegCommand;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegHandler;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentHandler;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentQuery;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Contract\ReceivingDockRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Cobertura de las ramas de guarda de los handlers de comando/consulta del contexto network: tipos
 * desconocidos, nodos/tramos inexistentes y duplicados. Son caminos que la puerta HTTP no alcanza
 * (el FormRequest rechaza antes los enums), así que se ejercitan directo sobre el handler con
 * repositorios en memoria. Todo PHP puro, sin base de datos.
 */
function networkGuardsRecorder(): RecordDomainEvents
{
    $store = new class implements OutboxStore
    {
        public function append(OutboxMessage $message): void {}

        /**
         * @return list<OutboxMessage>
         */
        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        }
    };

    return new RecordDomainEvents($store, $clock);
}

/**
 * Repositorio de nodos en memoria: resuelve findById por un mapa id => Node y findByCode por un único
 * nodo opcional. El resto de operaciones son inertes (los caminos de guarda no las alcanzan).
 *
 * @param array<string, Node> $byId
 */
function networkGuardsNodeRepo(array $byId = [], ?Node $byCode = null): NodeRepository
{
    return new class($byId, $byCode) implements NodeRepository
    {
        /**
         * @param array<string, Node> $byId
         */
        public function __construct(private array $byId, private ?Node $byCode) {}

        public function nextIdentity(): NodeId
        {
            return NodeId::generate();
        }

        public function save(Node $node): void {}

        public function findById(NodeId $id): ?Node
        {
            return $this->byId[$id->toString()] ?? null;
        }

        public function findByCode(NodeCode $code): ?Node
        {
            return $this->byCode;
        }

        /**
         * @return list<Node>
         */
        public function all(): array
        {
            return array_values($this->byId);
        }
    };
}

/**
 * Repositorio de tramos en memoria: resuelve findById y findByEndpoints por tramos opcionales.
 */
function networkGuardsLegRepo(?Leg $byEndpoints = null, ?Leg $byId = null): LegRepository
{
    return new class($byEndpoints, $byId) implements LegRepository
    {
        public function __construct(private ?Leg $byEndpoints, private ?Leg $byId) {}

        public function nextIdentity(): LegId
        {
            return LegId::generate();
        }

        public function save(Leg $leg): void {}

        public function remove(Leg $leg): void {}

        public function findById(LegId $id): ?Leg
        {
            return $this->byId;
        }

        public function findByEndpoints(NodeId $from, NodeId $to, CorridorType $corridor): ?Leg
        {
            return $this->byEndpoints;
        }

        /**
         * @return list<Leg>
         */
        public function all(): array
        {
            return [];
        }
    };
}

/**
 * Repositorio de andenes en memoria, inerte: los caminos de guarda probados aquí fallan antes de
 * tocarlo.
 */
function networkGuardsDockRepo(): ReceivingDockRepository
{
    return new class implements ReceivingDockRepository
    {
        public function nextIdentity(): ReceivingDockId
        {
            return ReceivingDockId::generate();
        }

        public function save(ReceivingDock $dock): void {}

        public function findById(ReceivingDockId $id): ?ReceivingDock
        {
            return null;
        }

        public function findByCode(NodeId $warehouseId, DockCode $code): ?ReceivingDock
        {
            return null;
        }

        /**
         * @return list<ReceivingDock>
         */
        public function byWarehouse(NodeId $warehouseId): array
        {
            return [];
        }
    };
}

/**
 * Registra un nodo de dominio con código, tipo y departamento por defecto, para poblar los fakes.
 */
function networkGuardsNode(NodeId $id, NodeType $type = NodeType::DistributionCenter): Node
{
    return Node::register(
        $id,
        NodeCode::fromString('CD-LP-01'),
        'Nodo de prueba',
        $type,
        GeoPoint::of(-16.5, -68.15),
    );
}

it('rejects registering a node whose code already exists', function (): void {
    $existing = networkGuardsNode(NodeId::generate());
    $handler = new RegisterNodeHandler(networkGuardsNodeRepo([], $existing), networkGuardsRecorder());

    expect(fn () => $handler->handle(
        new RegisterNodeCommand('CD-LP-01', 'Dup', 'distribution_center', -16.5, -68.15, 'la_paz'),
    ))->toThrow(NetworkException::class, 'ya existe un nodo');
});

it('rejects registering a node with an unknown type', function (): void {
    $handler = new RegisterNodeHandler(networkGuardsNodeRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(
        new RegisterNodeCommand('CD-LP-02', 'Nodo', 'teleport', -16.5, -68.15, 'la_paz'),
    ))->toThrow(NetworkException::class, 'tipo de nodo desconocido');
});

it('rejects registering a node with an unknown department', function (): void {
    $handler = new RegisterNodeHandler(networkGuardsNodeRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(
        new RegisterNodeCommand('CD-LP-03', 'Nodo', 'distribution_center', -16.5, -68.15, 'atlantis'),
    ))->toThrow(NetworkException::class, 'departamento desconocido');
});

it('rejects deactivating a node that does not exist', function (): void {
    $handler = new DeactivateNodeHandler(networkGuardsNodeRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(
        new DeactivateNodeCommand('01920000-0000-7000-8000-000000000000'),
    ))->toThrow(NetworkException::class, 'no existe el nodo');
});

it('rejects defining a leg with an unknown corridor type', function (): void {
    $handler = new DefineLegHandler(networkGuardsLegRepo(), networkGuardsNodeRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(new DefineLegCommand(
        fromNodeId: '01920000-0000-7000-8000-000000000001',
        toNodeId: '01920000-0000-7000-8000-000000000002',
        corridorType: 'wormhole',
    )))->toThrow(NetworkException::class, 'tipo de corredor desconocido');
});

it('rejects defining a leg whose origin node does not exist', function (): void {
    $handler = new DefineLegHandler(networkGuardsLegRepo(), networkGuardsNodeRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(new DefineLegCommand(
        fromNodeId: '01920000-0000-7000-8000-000000000001',
        toNodeId: '01920000-0000-7000-8000-000000000002',
        corridorType: 'cd_to_store',
    )))->toThrow(NetworkException::class, 'no existe el nodo de origen');
});

it('rejects defining a leg whose destination node does not exist', function (): void {
    $fromId = NodeId::generate();
    $from = networkGuardsNode($fromId, NodeType::DistributionCenter);
    $handler = new DefineLegHandler(
        networkGuardsLegRepo(),
        networkGuardsNodeRepo([$fromId->toString() => $from]),
        networkGuardsRecorder(),
    );

    expect(fn () => $handler->handle(new DefineLegCommand(
        fromNodeId: $fromId->toString(),
        toNodeId: '01920000-0000-7000-8000-000000000002',
        corridorType: 'cd_to_store',
    )))->toThrow(NetworkException::class, 'no existe el nodo de destino');
});

it('rejects defining a leg that already exists', function (): void {
    $fromId = NodeId::generate();
    $toId = NodeId::generate();
    $from = networkGuardsNode($fromId, NodeType::DistributionCenter);
    $to = networkGuardsNode($toId, NodeType::Store);

    $existing = Leg::define(
        LegId::generate(),
        $fromId,
        NodeType::DistributionCenter,
        $toId,
        NodeType::Store,
        CorridorType::from('cd_to_store'),
    );

    $handler = new DefineLegHandler(
        networkGuardsLegRepo(byEndpoints: $existing),
        networkGuardsNodeRepo([$fromId->toString() => $from, $toId->toString() => $to]),
        networkGuardsRecorder(),
    );

    expect(fn () => $handler->handle(new DefineLegCommand(
        fromNodeId: $fromId->toString(),
        toNodeId: $toId->toString(),
        corridorType: 'cd_to_store',
    )))->toThrow(NetworkException::class, 'ya existe un tramo');
});

it('rejects removing a leg that does not exist', function (): void {
    $handler = new RemoveLegHandler(networkGuardsLegRepo(), networkGuardsRecorder());

    expect(fn () => $handler->handle(
        new RemoveLegCommand('01920000-0000-7000-8000-000000000000'),
    ))->toThrow(NetworkException::class, 'no existe el tramo');
});

it('rejects a receiving dock whose warehouse node does not exist', function (): void {
    $handler = new RegisterReceivingDockHandler(
        networkGuardsDockRepo(),
        networkGuardsNodeRepo(),
        networkGuardsRecorder(),
    );

    expect(fn () => $handler->handle(new RegisterReceivingDockCommand(
        '01920000-0000-7000-8000-000000000000',
        'A1',
        'Dock A1',
        2,
    )))->toThrow(NetworkException::class, 'no existe el nodo');
});

it('rejects a receiving dock on a node that does not receive inbound', function (): void {
    $warehouseId = NodeId::generate();
    $node = networkGuardsNode($warehouseId, NodeType::DistributionCenter);
    $handler = new RegisterReceivingDockHandler(
        networkGuardsDockRepo(),
        networkGuardsNodeRepo([$warehouseId->toString() => $node]),
        networkGuardsRecorder(),
    );

    expect(fn () => $handler->handle(new RegisterReceivingDockCommand(
        $warehouseId->toString(),
        'A1',
        'Dock A1',
        2,
    )))->toThrow(NetworkException::class, 'almacén central');
});

it('rejects operational corridors for an unknown department', function (): void {
    $handler = new OperationalCorridorsByDepartmentHandler(
        networkGuardsNodeRepo(),
        networkGuardsLegRepo(),
    );

    expect(fn () => $handler->handle(new OperationalCorridorsByDepartmentQuery('narnia')))
        ->toThrow(NetworkException::class, 'departamento desconocido');
});
