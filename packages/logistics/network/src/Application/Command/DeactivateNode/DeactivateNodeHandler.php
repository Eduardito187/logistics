<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\DeactivateNode;

use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Da de baja un nodo: lo carga, lo desactiva, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DeactivateNodeCommand>
 */
final readonly class DeactivateNodeHandler implements CommandHandler
{
    public function __construct(
        private NodeRepository $nodes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeactivateNodeCommand);

        $id = NodeId::fromString($command->nodeId);
        $node = $this->nodes->findById($id)
            ?? throw NetworkException::because("no existe el nodo {$command->nodeId}");

        $node->deactivate();

        $this->nodes->save($node);

        $this->recordEvents->record($node->releaseEvents(), 'network.node');
    }
}
