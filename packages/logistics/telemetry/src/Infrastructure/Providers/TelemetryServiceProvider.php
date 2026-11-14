<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Providers;

use App\Support\ServiceRole;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlertHandler;
use Logistics\Telemetry\Application\Command\ActivateGeofence\ActivateGeofence;
use Logistics\Telemetry\Application\Command\ActivateGeofence\ActivateGeofenceHandler;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTraffic;
use Logistics\Telemetry\Application\Command\AggregateStreetTraffic\AggregateStreetTrafficHandler;
use Logistics\Telemetry\Application\Command\BindTripToRoute\BindTripToRoute;
use Logistics\Telemetry\Application\Command\BindTripToRoute\BindTripToRouteHandler;
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
use Logistics\Telemetry\Application\Command\RefreshVehicleEtas\RefreshVehicleEtas;
use Logistics\Telemetry\Application\Command\RefreshVehicleEtas\RefreshVehicleEtasHandler;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofence;
use Logistics\Telemetry\Application\Command\RenameGeofence\RenameGeofenceHandler;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\Contract\LivePositionHotState;
use Logistics\Telemetry\Application\Contract\PingFlushScheduler;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Logistics\Telemetry\Application\Contract\ShipmentCustodyReader;
use Logistics\Telemetry\Application\Query\ClassifyPingFixes\ClassifyPingFixes;
use Logistics\Telemetry\Application\Query\ClassifyPingFixes\ClassifyPingFixesHandler;
use Logistics\Telemetry\Application\Query\CountAlerts\CountAlerts;
use Logistics\Telemetry\Application\Query\CountAlerts\CountAlertsHandler;
use Logistics\Telemetry\Application\Query\GetGeofence\GetGeofence;
use Logistics\Telemetry\Application\Query\GetGeofence\GetGeofenceHandler;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositionsHandler;
use Logistics\Telemetry\Application\Query\GetShipmentBreadcrumbs\GetShipmentBreadcrumbs;
use Logistics\Telemetry\Application\Query\GetShipmentBreadcrumbs\GetShipmentBreadcrumbsHandler;
use Logistics\Telemetry\Application\Query\GetShipmentEta\GetShipmentEta;
use Logistics\Telemetry\Application\Query\GetShipmentEta\GetShipmentEtaHandler;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTraffic;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTrafficHandler;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtas;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtasHandler;
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
use Logistics\Telemetry\Domain\Contract\LivePositionBroadcaster;
use Logistics\Telemetry\Domain\Contract\MapMatcher;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Contract\RoadDurationEstimator;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;
use Logistics\Telemetry\Domain\Contract\StreetStatsRepository;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Infrastructure\Console\AggregateStreetTrafficCommand;
use Logistics\Telemetry\Infrastructure\Console\FlushPingBufferCommand;
use Logistics\Telemetry\Infrastructure\Console\PruneTelemetryPingsCommand;
use Logistics\Telemetry\Infrastructure\Console\RefreshStopEtasCommand;
use Logistics\Telemetry\Infrastructure\CrossContext\DbActiveRouteReader;
use Logistics\Telemetry\Infrastructure\CrossContext\DbFleetDirectory;
use Logistics\Telemetry\Infrastructure\CrossContext\DbShipmentCustodyReader;
use Logistics\Telemetry\Infrastructure\Ingest\QueuedPingFlushScheduler;
use Logistics\Telemetry\Infrastructure\Ingest\RedisPingIngestBuffer;
use Logistics\Telemetry\Infrastructure\LiveState\BroadcastingLivePositionBroadcaster;
use Logistics\Telemetry\Infrastructure\LiveState\FleetPlateResolver;
use Logistics\Telemetry\Infrastructure\LiveState\LiveVehicleState;
use Logistics\Telemetry\Infrastructure\Outbox\BindTripToRouteOnRouteStarted;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentAlertRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentGeofenceRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentPingRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentStopEtaRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentStreetStatsRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentTripRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository\EloquentVehiclePositionRepository;
use Logistics\Telemetry\Infrastructure\Routing\OsrmMapMatcher;
use Logistics\Telemetry\Infrastructure\Routing\OsrmRoadDurationEstimator;

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
        BindTripToRoute::class => BindTripToRouteHandler::class,
        ClassifyPingFixes::class => ClassifyPingFixesHandler::class,
        GetLiveFleetPositions::class => GetLiveFleetPositionsHandler::class,
        GetVehicleTrack::class => GetVehicleTrackHandler::class,
        ListTrips::class => ListTripsHandler::class,
        GetVehicleTelemetrySummary::class => GetVehicleTelemetrySummaryHandler::class,

        // Seguimiento por envío: migas de pan + ETA dinámica (y su recálculo programado).
        GetShipmentBreadcrumbs::class => GetShipmentBreadcrumbsHandler::class,
        GetVehicleEtas::class => GetVehicleEtasHandler::class,
        GetShipmentEta::class => GetShipmentEtaHandler::class,
        RefreshVehicleEtas::class => RefreshVehicleEtasHandler::class,

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
        CountAlerts::class => CountAlertsHandler::class,

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
        $this->app->bind(StopEtaRepository::class, EloquentStopEtaRepository::class);
        // Duraciones viales para ETA (OSRM). Solo lo usa el comando programado; sin host configurado devuelve null.
        $this->app->bind(RoadDurationEstimator::class, OsrmRoadDurationEstimator::class);

        // Lecturas cross-context de solo lectura (fleet / execution / planning / shipments) por query builder.
        $this->app->bind(FleetDirectory::class, DbFleetDirectory::class);
        $this->app->bind(ShipmentCustodyReader::class, DbShipmentCustodyReader::class);
        $this->app->bind(ActiveRouteReader::class, DbActiveRouteReader::class);

        // Fan-out en vivo: adaptador que difunde por WebSockets + mantiene el hot-state en Valkey.
        // El resolver de placas es singleton para memoizar id→placa (cross-context read-only) y evitar N+1.
        $this->app->singleton(FleetPlateResolver::class);
        $this->app->singleton(LiveVehicleState::class);
        $this->app->bind(LivePositionBroadcaster::class, BroadcastingLivePositionBroadcaster::class);

        // Ingesta dedicada (modo `buffered`): buffer en Valkey, drenado por job en la cola `telemetry` y
        // última posición caliente adelantada en el momento del POST (mismo hot-state del fan-out).
        $this->app->bind(PingIngestBuffer::class, RedisPingIngestBuffer::class);
        $this->app->bind(PingFlushScheduler::class, QueuedPingFlushScheduler::class);
        $this->app->bind(LivePositionHotState::class, LiveVehicleState::class);

        // Reactor del outbox (Fase 3.3): al iniciar la ruta del chofer, liga el trip activo del vehículo a
        // la ruta. El relay lo compone por eventName (ver routing del outbox); se bindea para resolverlo con
        // su dependencia (bus de comandos).
        $this->app->bind(BindTripToRouteOnRouteStarted::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        // Vistas del package (mapas Leaflet del admin): namespace `logistics-telemetry::`.
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-telemetry');

        // Rol de despliegue (config/service.php): con `monolith` es un no-op; con un rol acotado (p. ej.
        // `telemetry`) el proceso atiende solo las rutas HTTP de ese rol. Ver docs/perf/extraccion-telemetry.md.
        ServiceRole::enforce($this->app);

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AggregateStreetTrafficCommand::class,
                FlushPingBufferCommand::class,
                PruneTelemetryPingsCommand::class,
                RefreshStopEtasCommand::class,
            ]);

            // Agregación diaria de calles en el scheduler (map-matching del día anterior).
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('telemetry:aggregate-streets')
                    ->dailyAt('01:15')
                    ->withoutOverlapping();

                // ETA dinámica por parada desde la última posición (OSRM solo acá, fuera de los requests).
                $schedule->command('telemetry:refresh-etas')
                    ->everyMinute()
                    ->withoutOverlapping(5);

                // Drenado de respaldo del buffer de ingesta (modo `buffered`): el camino normal es el job de la
                // cola `telemetry`; esto evita que un lote quede varado si el worker cae. Sin withoutOverlapping
                // a propósito: el drenado ya lleva su propio candado por fragmento, con vencimiento.
                $schedule->command('telemetry:flush-ping-buffer')->everyMinute();
            });
        }
    }
}
