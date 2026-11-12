<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RegisterDeparture;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Contract\VehicleFitnessGate;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\Load;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la salida, la persiste y publica sus eventos al outbox dentro de la misma transacción (la
 * abre el decorador transaccional del CommandBus).
 *
 * @implements CommandHandler<RegisterDeparture>
 */
final readonly class RegisterDepartureHandler implements CommandHandler
{
    public function __construct(
        private DepartureRepository $departures,
        private RecordDomainEvents $recordEvents,
        private VehicleFitnessGate $fitness,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterDeparture);

        $departedAt = new DateTimeImmutable($command->departedAt);

        // Regla de negocio (#27): un vehículo NO APTO (checklist pre-salida con falla crítica sin habilitar)
        // no sale a ruta. 422 con el motivo legible; no se registra nada.
        $unfitReason = $this->fitness->unfitReason($command->vehicleId, $departedAt);
        if ($unfitReason !== null) {
            throw FleetOpsException::because($unfitReason);
        }

        $departure = Departure::register(
            $this->departures->nextIdentity(),
            $command->vehicleId,
            $command->driverId,
            $command->helperIds,
            $command->destination,
            $command->routeRef,
            Load::fromArray($command->load),
            $command->odometerOutKm !== null ? Odometer::km($command->odometerOutKm) : null,
            $departedAt,
            $command->note,
        );

        $this->departures->save($departure);

        $this->recordEvents->record($departure->releaseEvents(), 'fleetops.departure');
    }
}
