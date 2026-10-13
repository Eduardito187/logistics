<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Arma la tripulación, la persiste y libera sus eventos.
 *
 * TODO(outbox): publicar los eventos liberados cuando se integre el package outbox.
 *
 * @implements CommandHandler<AssembleCrew>
 */
final readonly class AssembleCrewHandler implements CommandHandler
{
    public function __construct(
        private CrewRepository $crews,
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
        $crew->releaseEvents();
    }
}
