<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\DefineLeg;

use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Define un tramo: valida que ambos nodos existan, construye el agregado, lo persiste y publica sus
 * eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DefineLegCommand>
 */
final readonly class DefineLegHandler implements CommandHandler
{
    public function __construct(
        private LegRepository $legs,
        private NodeRepository $nodes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineLegCommand);

        $from = NodeId::fromString($command->fromNodeId);
        $to = NodeId::fromString($command->toNodeId);

        $corridor = CorridorType::tryFrom($command->corridorType)
            ?? throw NetworkException::because("tipo de corredor desconocido: {$command->corridorType}");

        $fromNode = $this->nodes->findById($from)
            ?? throw NetworkException::because("no existe el nodo de origen {$command->fromNodeId}");
        $toNode = $this->nodes->findById($to)
            ?? throw NetworkException::because("no existe el nodo de destino {$command->toNodeId}");

        if ($this->legs->findByEndpoints($from, $to, $corridor) !== null) {
            throw NetworkException::because('ya existe un tramo con ese origen, destino y corredor');
        }

        // El agregado valida que los tipos de nodo calcen con el corredor; acá solo se los pasamos.
        $leg = Leg::define(
            $this->legs->nextIdentity(),
            $from,
            $fromNode->type(),
            $to,
            $toNode->type(),
            $corridor,
        );

        $this->legs->save($leg);

        $this->recordEvents->record($leg->releaseEvents(), 'network.leg');
    }
}
