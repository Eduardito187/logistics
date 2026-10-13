<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RemoveLeg;

use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Elimina un tramo: lo carga, lo marca como removido, borra su fila y publica sus eventos al outbox.
 *
 * El borrado del repositorio y el append al outbox corren dentro de la misma transacción, que abre
 * el decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RemoveLegCommand>
 */
final readonly class RemoveLegHandler implements CommandHandler
{
    public function __construct(
        private LegRepository $legs,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RemoveLegCommand);

        $id = LegId::fromString($command->legId);
        $leg = $this->legs->findById($id)
            ?? throw NetworkException::because("no existe el tramo {$command->legId}");

        $leg->remove();

        $this->legs->remove($leg);

        $this->recordEvents->record($leg->releaseEvents(), 'network.leg');
    }
}
