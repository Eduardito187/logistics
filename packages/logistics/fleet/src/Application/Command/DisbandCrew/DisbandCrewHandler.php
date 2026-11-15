<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\DisbandCrew;

use Logistics\Fleet\Application\Contract\CrewDirectory;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Da de baja la asignación. Si no existe lanza FleetException (el controller responde 404/422): quitar algo
 * que no está es un error del pedido, no un no-op silencioso.
 *
 * @implements CommandHandler<DisbandCrew>
 */
final readonly class DisbandCrewHandler implements CommandHandler
{
    public function __construct(
        private CrewDirectory $crews,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DisbandCrew);

        if (!$this->crews->remove($command->crewId)) {
            throw FleetException::because('La tripulación no existe.');
        }
    }
}
