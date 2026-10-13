<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Arma la tripulación, la persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<AssembleCrew>
 */
final readonly class AssembleCrewHandler implements CommandHandler
{
    public function __construct(
        private CrewRepository $crews,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AssembleCrew);

        $crew = Crew::assemble(
            $this->crews->nextIdentity(),
            DriverId::fromString($command->driverId),
            array_values($command->helperIds),
            VehicleId::fromString($command->vehicleId),
            new DateTimeImmutable($command->assembledFor),
        );

        $this->crews->save($crew);

        $this->recordEvents->record($crew->releaseEvents(), 'fleet.crew');
    }
}
