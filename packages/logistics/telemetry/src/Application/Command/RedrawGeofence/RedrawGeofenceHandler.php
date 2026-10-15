<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RedrawGeofence;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\Polygon;

/**
 * Redibuja el polígono de una geocerca y publica sus eventos al outbox.
 *
 * @implements CommandHandler<RedrawGeofence>
 */
final readonly class RedrawGeofenceHandler implements CommandHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RedrawGeofence);

        $geofence = $this->geofences->findById(GeofenceId::fromString($command->geofenceId));
        if ($geofence === null) {
            throw TelemetryException::because("No existe la geocerca {$command->geofenceId}.");
        }

        $geofence->redraw(Polygon::fromArray($command->polygon));

        $this->geofences->save($geofence);

        $this->recordEvents->record($geofence->releaseEvents(), 'telemetry.geofence');
    }
}
