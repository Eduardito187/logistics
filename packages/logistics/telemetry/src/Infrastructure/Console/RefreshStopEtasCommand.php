<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RefreshVehicleEtas\RefreshVehicleEtas;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Throwable;

/**
 * Recalcula la ETA dinámica por parada de todos los vehículos con ruta del día abierta (o de uno solo). Corre
 * en el scheduler cada minuto; es el ÚNICO lugar donde se consulta OSRM para ETAs (fuera de cualquier request).
 *
 * Un comando por vehículo (transacción corta por vehículo); un fallo de un vehículo no corta el resto.
 * Texto visible en inglés (regla de admin/consola); comentarios en español.
 */
final class RefreshStopEtasCommand extends Command
{
    protected $signature = 'telemetry:refresh-etas {--vehicle= : Limit to a single vehicle id}';

    protected $description = 'Recalculate the dynamic ETA of every remaining stop from the latest vehicle position.';

    public function handle(CommandBus $commands, ActiveRouteReader $routes): int
    {
        $vehicle = $this->option('vehicle');

        if (is_string($vehicle) && $vehicle !== '') {
            $vehicleIds = [$vehicle];
        } else {
            $timezone = (string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz');
            $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->setTime(0, 0);
            $vehicleIds = $routes->vehiclesWithOpenRoutes($today);
        }

        $failed = 0;
        foreach ($vehicleIds as $vehicleId) {
            try {
                $commands->dispatch(new RefreshVehicleEtas($vehicleId));
            } catch (Throwable $e) {
                $failed++;
                $this->warn("ETA refresh failed for vehicle {$vehicleId}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('ETAs refreshed for %d vehicle(s), %d failed.', count($vehicleIds) - $failed, $failed));

        return self::SUCCESS;
    }
}
