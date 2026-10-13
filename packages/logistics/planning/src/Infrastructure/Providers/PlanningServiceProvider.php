<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicleHandler;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposalHandler;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlanHandler;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStopsHandler;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\GetPlan\GetPlanHandler;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDateHandler;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use Logistics\Planning\Infrastructure\Routing\GoogleRouteOptimizerAdapter;
use Logistics\Shared\Application\HandlerRegistry;

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
        AssignShipmentToVehicle::class => AssignShipmentToVehicleHandler::class,
        ReorderStops::class => ReorderStopsHandler::class,
        PublishPlan::class => PublishPlanHandler::class,
        GetPlan::class => GetPlanHandler::class,
        ListPlansByDate::class => ListPlansByDateHandler::class,
    ];

    public function register(): void
    {
        $this->app->bind(PlanRepository::class, EloquentPlanRepository::class);
        // El motor de rutas real (Google Route Optimization) se enchufa acá a futuro; hoy es un stub.
        $this->app->bind(RouteOptimizer::class, GoogleRouteOptimizerAdapter::class);
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
