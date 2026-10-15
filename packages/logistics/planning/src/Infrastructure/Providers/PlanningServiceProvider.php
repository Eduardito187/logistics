<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicleHandler;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlan;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlanHandler;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposalHandler;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlanHandler;
use Logistics\Planning\Application\Command\ReassignShipmentToVehicle\ReassignShipmentToVehicle;
use Logistics\Planning\Application\Command\ReassignShipmentToVehicle\ReassignShipmentToVehicleHandler;
use Logistics\Planning\Application\Command\ReoptimizeRoute\ReoptimizeRoute;
use Logistics\Planning\Application\Command\ReoptimizeRoute\ReoptimizeRouteHandler;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStopsHandler;
use Logistics\Planning\Application\Command\ScheduleReplan\ScheduleReplan;
use Logistics\Planning\Application\Command\ScheduleReplan\ScheduleReplanHandler;
use Logistics\Planning\Application\Query\CompareRoutingBackends\CompareRoutingBackends;
use Logistics\Planning\Application\Query\CompareRoutingBackends\CompareRoutingBackendsHandler;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRun;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRunHandler;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\GetPlan\GetPlanHandler;
use Logistics\Planning\Application\Query\GetRouteQuality\GetRouteQuality;
use Logistics\Planning\Application\Query\GetRouteQuality\GetRouteQualityHandler;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDateHandler;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\Contract\DailyPlanDispatcher;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteComparisonProblemProvider;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\RouteLoadProfileProvider;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Service\ClarkeWrightSolver;
use Logistics\Planning\Domain\Service\DailyPlanComposer;
use Logistics\Planning\Domain\Service\DepartmentRouteGrouping;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\Service\VehicleCompatibility;
use Logistics\Planning\Infrastructure\Adapter\BlackoutAwarePendingDeliveryProvider;
use Logistics\Planning\Infrastructure\Adapter\EloquentPendingDeliveryProvider;
use Logistics\Planning\Infrastructure\Adapter\EloquentRouteComparisonProblemProvider;
use Logistics\Planning\Infrastructure\Adapter\EloquentRouteLoadProfileProvider;
use Logistics\Planning\Infrastructure\Adapter\FleetVehicleAvailabilityProvider;
use Logistics\Planning\Infrastructure\Adapter\NetworkNodeOriginLocator;
use Logistics\Planning\Infrastructure\Outbox\VehicleIncidentReplanningDispatcher;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\EloquentAutoPlanRunRepository;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\EloquentReplanBacklogRepository;
use Logistics\Planning\Infrastructure\Queue\QueuedDailyPlanDispatcher;
use Logistics\Planning\Infrastructure\Routing\BuiltinRouteOptimizer;
use Logistics\Planning\Infrastructure\Routing\BuiltinRoutingBackend;
use Logistics\Planning\Infrastructure\Routing\OsrmRoadMatrixProvider;
use Logistics\Planning\Infrastructure\Routing\OsrmRouteGeometryProvider;
use Logistics\Planning\Infrastructure\Routing\OsrmRouteOptimizer;
use Logistics\Planning\Infrastructure\Routing\OsrmTripRoutingBackend;
use Logistics\Planning\Infrastructure\Routing\VroomRoutingBackend;
use Logistics\Planning\Infrastructure\Settings\ConfiguredAutoPlanSettings;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Registro del bounded context Planificación: propuesta pedido→camión, secuencia, ETA y plan de carga.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class PlanningServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        GeneratePlanProposal::class => GeneratePlanProposalHandler::class,
        GenerateDailyPlan::class => GenerateDailyPlanHandler::class,
        AssignShipmentToVehicle::class => AssignShipmentToVehicleHandler::class,
        ReassignShipmentToVehicle::class => ReassignShipmentToVehicleHandler::class,
        ReorderStops::class => ReorderStopsHandler::class,
        ReoptimizeRoute::class => ReoptimizeRouteHandler::class,
        ScheduleReplan::class => ScheduleReplanHandler::class,
        PublishPlan::class => PublishPlanHandler::class,
        GetPlan::class => GetPlanHandler::class,
        ListPlansByDate::class => ListPlansByDateHandler::class,
        GetDailyPlanRun::class => GetDailyPlanRunHandler::class,
        GetRouteQuality::class => GetRouteQualityHandler::class,
        CompareRoutingBackends::class => CompareRoutingBackendsHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-planning.php', 'logistics-planning');

        $this->app->bind(PlanRepository::class, EloquentPlanRepository::class);
        $this->app->bind(ReplanBacklogRepository::class, EloquentReplanBacklogRepository::class);
        $this->app->bind(AutoPlanRunRepository::class, EloquentAutoPlanRunRepository::class);

        // Auto-planificador diario: puertos de lectura (pedidos pendientes y flota disponible),
        // parámetros configurables (settings/config) y encolado asíncrono del cálculo pesado.
        // El proveedor de pendientes se envuelve con el decorador que descarta los pedidos cuyo municipio
        // tiene la fecha del plan bloqueada (feriado u otro motivo): el auto-planificador no planifica
        // entregas en un día bloqueado del municipio correspondiente.
        $this->app->bind(PendingDeliveryProvider::class, function ($app): PendingDeliveryProvider {
            return new BlackoutAwarePendingDeliveryProvider(
                $app->make(EloquentPendingDeliveryProvider::class),
                $app->make(MunicipalityLocator::class),
                $app->make(QueryBus::class),
            );
        });
        $this->app->bind(FleetAvailabilityProvider::class, FleetVehicleAvailabilityProvider::class);
        $this->app->bind(AutoPlanSettingsProvider::class, ConfiguredAutoPlanSettings::class);
        $this->app->bind(DailyPlanDispatcher::class, QueuedDailyPlanDispatcher::class);

        // Dispatcher del outbox: ante un incidente MAYOR de camión (execution.vehicle_incident_reported),
        // encola los envíos pendientes para replanificar el día siguiente. Lo compone el relay por eventName.
        $this->app->bind(VehicleIncidentReplanningDispatcher::class);

        // Proveedor de la MATRIZ real por carretera (OSRM /table) con caché en Valkey y degradación a
        // haversine, en lotes para conjuntos grandes. Base del ruteo real (Clarke-Wright, 2-opt).
        $this->app->singleton(RoadMatrixProvider::class, static function ($app): OsrmRoadMatrixProvider {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new OsrmRoadMatrixProvider(
                $app->make(HttpClient::class),
                $app->make(Cache::class),
                (string) $config->get('logistics-planning.osrm_host', 'https://router.project-osrm.org'),
                (int) $config->get('logistics-planning.osrm_timeout', 5),
                (int) $config->get('logistics-planning.routing.matrix.max_table_coordinates', 100),
                (int) $config->get('logistics-planning.routing.matrix.cache_ttl', 86400),
                (float) $config->get('logistics-planning.routing.fallback_speed_kmh', 30.0),
            );
        });

        // Proveedor de la GEOMETRÍA por calles (OSRM /route) que se persiste con el plan; degrada a la
        // línea recta entre paradas si el ruteador no responde.
        $this->app->singleton(RouteGeometryProvider::class, static function ($app): OsrmRouteGeometryProvider {
            /** @var Config $config */
            $config = $app->make(Config::class);

            return new OsrmRouteGeometryProvider(
                $app->make(HttpClient::class),
                (string) $config->get('logistics-planning.osrm_host', 'https://router.project-osrm.org'),
                (int) $config->get('logistics-planning.osrm_timeout', 5),
                (float) $config->get('logistics-planning.routing.fallback_speed_kmh', 30.0),
            );
        });

        // Resolución del nodo CD de origen (contexto Red) para usarlo de depósito en la matriz/geometría.
        $this->app->bind(PlanningOriginLocator::class, NetworkNodeOriginLocator::class);

        // Optimizador de ORDEN por camión (contrato RouteOptimizer, usado por la propuesta manual). Se
        // elige por el mismo backend configurado: builtin (matriz + 2-opt), osrm_trip (OSRM /trip) o, para
        // vroom, el builtin (VROOM resuelve el VRP completo, no el orden de un solo camión suelto).
        $this->app->bind(RouteOptimizer::class, function ($app): RouteOptimizer {
            return match ($this->backendName($app)) {
                'osrm_trip' => $this->osrmRouteOptimizer($app),
                default => new BuiltinRouteOptimizer(
                    $app->make(RoadMatrixProvider::class),
                    $app->make(RouteSequencer::class),
                ),
            };
        });

        // Backend ENCHUFABLE del auto-planificador (contrato RoutingBackend): asigna paradas a camiones,
        // ordena, calcula ETA y geometría. Seleccionado por configuración.
        $this->app->bind(RoutingBackend::class, function ($app): RoutingBackend {
            return match ($this->backendName($app)) {
                'osrm_trip' => new OsrmTripRoutingBackend(
                    $app->make(DailyPlanComposer::class),
                    $this->osrmRouteOptimizer($app),
                    $app->make(RouteGeometryProvider::class),
                ),
                'vroom' => $this->vroomRoutingBackend($app),
                default => $this->builtinRoutingBackend($app),
            };
        });

        // Métricas de calidad de ruta (R-06): puerta de lectura de carga de envíos y capacidad del camión.
        $this->app->bind(RouteLoadProfileProvider::class, EloquentRouteLoadProfileProvider::class);

        // Reconstrucción del problema de un plan para comparar backends (R-06).
        $this->app->bind(RouteComparisonProblemProvider::class, EloquentRouteComparisonProblemProvider::class);

        // Handler de comparación de backends (R-06): recibe los TRES backends nombrados (builtin/osrm/vroom)
        // para correrlos sobre el mismo plan, más el costo/km configurable. Se bindea a mano por el array.
        $this->app->bind(CompareRoutingBackendsHandler::class, function ($app): CompareRoutingBackendsHandler {
            /** @var Config $config */
            $config = $app->make(Config::class);

            $backends = [
                'builtin' => $this->builtinRoutingBackend($app),
                'osrm_trip' => new OsrmTripRoutingBackend(
                    $app->make(DailyPlanComposer::class),
                    $this->osrmRouteOptimizer($app),
                    $app->make(RouteGeometryProvider::class),
                ),
                'vroom' => $this->vroomRoutingBackend($app),
            ];

            return new CompareRoutingBackendsHandler(
                $app->make(RouteComparisonProblemProvider::class),
                $app->make(AutoPlanSettingsProvider::class),
                $backends,
                (int) $config->get('logistics-planning.routing.quality.cost_per_km_cents', 350),
                (string) $config->get('logistics-planning.routing.quality.currency', 'BOB'),
            );
        });
    }

    /**
     * Nombre del backend de ruteo configurado (builtin por defecto).
     */
    private function backendName(Application $app): string
    {
        /** @var Config $config */
        $config = $app->make(Config::class);

        return (string) $config->get('logistics-planning.routing.backend', 'builtin');
    }

    private function osrmRouteOptimizer(Application $app): OsrmRouteOptimizer
    {
        /** @var Config $config */
        $config = $app->make(Config::class);

        return new OsrmRouteOptimizer(
            (string) $config->get('logistics-planning.osrm_host', 'https://router.project-osrm.org'),
            (int) $config->get('logistics-planning.osrm_timeout', 5),
        );
    }

    private function builtinRoutingBackend(Application $app): BuiltinRoutingBackend
    {
        return new BuiltinRoutingBackend(
            $app->make(RoadMatrixProvider::class),
            $app->make(RouteGeometryProvider::class),
            $app->make(ClarkeWrightSolver::class),
            $app->make(RouteSequencer::class),
            $app->make(DepartmentRouteGrouping::class),
            $app->make(VehicleCompatibility::class),
        );
    }

    private function vroomRoutingBackend(Application $app): VroomRoutingBackend
    {
        /** @var Config $config */
        $config = $app->make(Config::class);
        /** @var mixed $vroomUrl */
        $vroomUrl = $config->get('logistics-planning.routing.vroom_url');

        return new VroomRoutingBackend(
            $app->make(HttpClient::class),
            $app->make(RouteGeometryProvider::class),
            $app->make(DepartmentRouteGrouping::class),
            $this->builtinRoutingBackend($app),
            is_string($vroomUrl) ? $vroomUrl : null,
            (int) $config->get('logistics-planning.osrm_timeout', 5),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
