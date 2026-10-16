<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RegisterReceivingDock;

use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Contract\ReceivingDockRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\DockCapacity;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra un andén de recepción: valida que el almacén exista y reciba de proveedores (almacén
 * central), construye el agregado, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterReceivingDockCommand>
 */
final readonly class RegisterReceivingDockHandler implements CommandHandler
{
    public function __construct(
        private ReceivingDockRepository $docks,
        private NodeRepository $nodes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterReceivingDockCommand);

        $warehouseId = NodeId::fromString($command->warehouseId);
        $warehouse = $this->nodes->findById($warehouseId)
            ?? throw NetworkException::because("no existe el nodo {$command->warehouseId} para el andén");

        if (!$warehouse->type()->receivesInbound()) {
            throw NetworkException::because('un andén de recepción solo puede colgar de un almacén central');
        }

        $code = DockCode::fromString($command->code);
        if ($this->docks->findByCode($warehouseId, $code) !== null) {
            throw NetworkException::because("ya existe un andén con el código {$code->toString()} en ese almacén");
        }

        $dock = ReceivingDock::register(
            $this->docks->nextIdentity(),
            $warehouseId,
            $code,
            $command->name,
            DockCapacity::of($command->appointmentsPerSlot),
        );

        $this->docks->save($dock);

        $this->recordEvents->record($dock->releaseEvents(), 'network.receiving_dock');
    }
}
