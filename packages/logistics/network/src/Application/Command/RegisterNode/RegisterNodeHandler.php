<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RegisterNode;

use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra un nodo: construye el agregado, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterNodeCommand>
 */
final readonly class RegisterNodeHandler implements CommandHandler
{
    public function __construct(
        private NodeRepository $nodes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterNodeCommand);

        $code = NodeCode::fromString($command->code);
        if ($this->nodes->findByCode($code) !== null) {
            throw NetworkException::because("ya existe un nodo con el código {$code->toString()}");
        }

        $type = NodeType::tryFrom($command->type)
            ?? throw NetworkException::because("tipo de nodo desconocido: {$command->type}");

        $node = Node::register(
            $this->nodes->nextIdentity(),
            $code,
            $command->name,
            $type,
            GeoPoint::of($command->latitude, $command->longitude),
        );

        $this->nodes->save($node);

        $this->recordEvents->record($node->releaseEvents(), 'network.node');
    }
}
