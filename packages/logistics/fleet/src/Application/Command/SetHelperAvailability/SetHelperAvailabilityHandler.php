<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\SetHelperAvailability;

use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el ayudante, le cambia la disponibilidad y persiste. Falla si el ayudante no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetHelperAvailability>
 */
final readonly class SetHelperAvailabilityHandler implements CommandHandler
{
    public function __construct(
        private HelperRepository $helpers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetHelperAvailability);

        $helper = $this->helpers->findById(HelperId::fromString($command->helperId));
        if ($helper === null) {
            throw FleetException::because("No existe el ayudante {$command->helperId}.");
        }

        $helper->changeAvailability($command->active);

        $this->helpers->save($helper);

        $this->recordEvents->record($helper->releaseEvents(), 'fleet.helper');
    }
}
