<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTraffic;

/**
 * Agrega la telemetría GPS del día a estadísticas por calle (map-matching con OSRM): velocidad media,
 * muestras y congestión por segmento. Corre en el scheduler (diario) y también a mano para rellenar.
 *
 * Texto visible en inglés (regla de admin/consola); comentarios en español.
 */
final class AggregateStreetTrafficCommand extends Command
{
    protected $signature = 'telemetry:aggregate-streets {date? : Day to aggregate (YYYY-MM-DD); defaults to yesterday} {--vehicle= : Limit to a single vehicle id}';

    protected $description = 'Map-match vehicle GPS pings to streets and aggregate traffic stats (OSRM).';

    public function handle(CommandBus $commands): int
    {
        $date = $this->argument('date');
        $day = is_string($date) && $date !== '' ? $date : Carbon::yesterday()->toDateString();

        $vehicle = $this->option('vehicle');
        $vehicleId = is_string($vehicle) && $vehicle !== '' ? $vehicle : null;

        $commands->dispatch(new AggregateStreetTraffic($vehicleId, $day));

        $this->info("Street traffic aggregated for {$day}".($vehicleId !== null ? " (vehicle {$vehicleId})" : '').'.');

        return self::SUCCESS;
    }
}
