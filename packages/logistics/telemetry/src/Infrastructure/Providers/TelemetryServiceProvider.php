<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlertHandler;
use Logistics\Telemetry\Application\Command\ActivateGeofence\ActivateGeofence;
use Logistics\Telemetry\Application\Command\ActivateGeofence\ActivateGeofenceHandler;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTraffic;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTrafficHandler;
use Logistics\Telemetry\Application\Command\DeactivateGeofence\DeactivateGeofence;
use Logistics\Telemetry\Application\Command\DeactivateGeofence\DeactivateGeofenceHandler;
use Logistics\Telemetry\Application\Command\DefineGeofence\DefineGeofence;
use Logistics\Telemetry\Application\Command\DefineGeofence\DefineGeofenceHandler;
use Logistics\Telemetry\Application\Command\DeleteGeofence\DeleteGeofence;
use Logistics\Telemetry\Application\Command\DeleteGeofence\DeleteGeofenceHandler;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePingsHandler;
use Logistics\Telemetry\Application\Command\RedrawGeofence\RedrawGeofence;
use Logistics\Telemetry\Application\Command\RedrawGeofence\RedrawGeofenceHandler;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofence;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofenceHandler;
use Logistics\Telemetry\Application\Query\GetGeofence\GetGeofence;
use Logistics\Telemetry\Application\Query\GetGeofence\GetGeofenceHandler;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositionsHandler;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTraffic;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTrafficHandler;
use Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary\GetVehicleTelemetrySummary;
use Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary\GetVehicleTelemetrySummaryHandler;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrack;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrackHandler;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlertsHandler;
use Logistics\Telemetry\Application\Query\ListGeofences\ListGeofences;
use Logistics\Telemetry\Application\Query\ListGeofences\ListGeofencesHandler;
use Logistics\Telemetry\Application\Query\ListTrips\ListTrips;
use Logistics\Telemetry\Application\Query\ListTrips\ListTripsHandler;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Contract\MapMatcher;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Contract\StreetStatsRepository;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Infrastructure\Console\AggregateStreetTrafficCommand;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentAlertRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentGeofenceRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentPingRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentStreetStatsRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentTripRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentVehiclePositionRepository;
use Logistics\Telemetry\Infrastructure\Routing\OsrmMapMatcher;

/**
 * Registro del bounded context Telemetría: ingesta de GPS, posición en vivo, trips, geocercas, alertas
 * de mal uso y analítica de calles (OSRM map-matching). Se registra a mano en bootstrap/providers.php
 * (el package está en dont-discover) para que el orden lo controle el proyecto.
 */
final class TelemetryServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        // Ingesta + posición en vivo + trips.
        RecordVehiclePings::class => RecordVehiclePingsHandler::class,
        GetLiveFleetPositions::class => GetLiveFleetPositionsHandler::class,
        GetVehicleTrack::class => GetVehicleTrackHandler::class,
        ListTrips::class => ListTripsHandler::class,
        GetVehicleTelemetrySummary::class => GetVehicleTelemetrySummaryHandler::class,

        // Geocercas + alertas de mal uso.
        DefineGeofence::class => DefineGeofenceHandler::class,
        RenameGeofence::class => RenameGeofenceHandler::class,
        RedrawGeofence::class => RedrawGeofenceHandler::class,
        ActivateGeofence::class => ActivateGeofenceHandler::class,
        DeactivateGeofence::class => DeactivateGeofenceHandler::class,
        DeleteGeofence::class => DeleteGeofenceHandler::class,
        AcknowledgeAlert::class => AcknowledgeAlertHandler::class,
        ListGeofences::class => ListGeofencesHandler::class,
        GetGeofence::class => GetGeofenceHandler::class,
        ListAlerts::class => ListAlertsHandler::class,

        // Analítica de calles (worker + consulta).
        AggregateStreetTraffic::class => AggregateStreetTrafficHandler::class,
        GetStreetTraffic::class => GetStreetTrafficHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-telemetry.php', 'logistics-telemetry');

        $this->app->bind(PingRepository::class, EloquentPingRepository::class);
        $this->app->bind(VehiclePositionRepository::class, EloquentVehiclePositionRepository::class);
        $this->app->bind(TripRepository::class, EloquentTripRepository::class);
        $this->app->bind(GeofenceRepository::class, EloquentGeofenceRepository::class);
        $this->app->bind(AlertRepository::class, EloquentAlertRepository::class);
        $this->app->bind(StreetStatsRepository::class, EloquentStreetStatsRepository::class);
        $this->app->bind(MapMatcher::class, OsrmMapMatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        // Vistas del package (mapas Leaflet del admin): namespace `logistics-telemetry::`.
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-telemetry');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AggregateStreetTrafficCommand::class]);

            // Agregación diaria de calles en el scheduler (map-matching del día anterior).
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('telemetry:aggregate-streets')
                    ->dailyAt('01:15')
                    ->withoutOverlapping();
            });
        }
    }
}
