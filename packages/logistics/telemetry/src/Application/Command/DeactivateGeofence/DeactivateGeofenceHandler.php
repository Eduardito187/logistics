<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DeactivateGeofence;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;

/**
 * Desactiva una geocerca y publica sus eventos al outbox.
 *
 * @implements CommandHandler<DeactivateGeofence>
 */
final readonly class DeactivateGeofenceHandler implements CommandHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeactivateGeofence);

        $geofence = $this->geofences->findById(GeofenceId::fromString($command->geofenceId));
        if ($geofence === null) {
            throw TelemetryException::because("No existe la geocerca {$command->geofenceId}.");
        }

        $geofence->deactivate();

        $this->geofences->save($geofence);

        $this->recordEvents->record($geofence->releaseEvents(), 'telemetry.geofence');
    }
}
