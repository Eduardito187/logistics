<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\DeactivateNode;

use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Da de baja un nodo: lo carga, lo desactiva, lo persiste y libera sus eventos.
 *
 * @implements CommandHandler<DeactivateNodeCommand>
 */
final readonly class DeactivateNodeHandler implements CommandHandler
{
    public function __construct(private NodeRepository $nodes) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeactivateNodeCommand);

        $id = NodeId::fromString($command->nodeId);
        $node = $this->nodes->findById($id)
            ?? throw NetworkException::because("no existe el nodo {$command->nodeId}");

        $node->deactivate();

        $this->nodes->save($node);

        // TODO: publicar al outbox dentro de la transacción cuando el outbox esté integrado (ADR 002).
        $node->releaseEvents();
    }
}
