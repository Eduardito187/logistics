<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DefineGeofence;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\Polygon;

/**
 * Construye la geocerca, la persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<DefineGeofence>
 */
final readonly class DefineGeofenceHandler implements CommandHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineGeofence);

        $geofence = Geofence::define(
            $this->geofences->nextIdentity(),
            $command->name,
            GeofenceType::from($command->type),
            Polygon::fromArray($command->polygon),
            $command->nodeRef,
        );

        $this->geofences->save($geofence);

        $this->recordEvents->record($geofence->releaseEvents(), 'telemetry.geofence');
    }
}
