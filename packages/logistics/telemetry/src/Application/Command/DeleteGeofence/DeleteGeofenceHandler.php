<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DeleteGeofence;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;

/**
 * Elimina una geocerca. Idempotente: borrar una geocerca inexistente no es un error.
 *
 * El borrado no emite evento de dominio (el agregado deja de existir); si en el futuro se necesita
 * auditarlo, se modela como un evento GeofenceDeleted sin cambiar esta firma.
 *
 * @implements CommandHandler<DeleteGeofence>
 */
final readonly class DeleteGeofenceHandler implements CommandHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeleteGeofence);

        $this->geofences->delete(GeofenceId::fromString($command->geofenceId));
    }
}
