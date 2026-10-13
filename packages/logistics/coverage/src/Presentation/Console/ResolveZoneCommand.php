<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resuelve en qué zona de cobertura cae un punto (lat, lng) desde la consola. Útil para verificar la
 * importación del mapa contra coordenadas reales.
 */
final class ResolveZoneCommand extends Command
{
    protected $signature = 'logistics:coverage:resolve {lat : Latitude (-90..90)} {lng : Longitude (-180..180)}';

    protected $description = 'Resolve which coverage zone contains a given point';

    public function handle(QueryBus $queryBus): int
    {
        $latArg = $this->argument('lat');
        $lngArg = $this->argument('lng');
        $lat = is_numeric($latArg) ? (float) $latArg : 0.0;
        $lng = is_numeric($lngArg) ? (float) $lngArg : 0.0;

        $view = $queryBus->ask(new ResolveZoneForPoint($lat, $lng));
        if (!$view instanceof ZoneView) {
            $this->warn("No coverage zone contains the point ({$lat}, {$lng}).");

            return self::FAILURE;
        }

        $this->info("Zone: {$view->name} ({$view->id})");
        $this->line((string) json_encode($view->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
