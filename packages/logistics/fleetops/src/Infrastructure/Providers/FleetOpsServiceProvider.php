<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Logistics\FleetOps\Application\Command\ActivatePlan\ActivatePlan;
use Logistics\FleetOps\Application\Command\ActivatePlan\ActivatePlanHandler;
use Logistics\FleetOps\Application\Command\CancelMaintenance\CancelMaintenance;
use Logistics\FleetOps\Application\Command\CancelMaintenance\CancelMaintenanceHandler;
use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDeparture;
use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDepartureHandler;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenance;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenanceHandler;
use Logistics\FleetOps\Application\Command\DeactivatePlan\DeactivatePlan;
use Logistics\FleetOps\Application\Command\DeactivatePlan\DeactivatePlanHandler;
use Logistics\FleetOps\Application\Command\DefineMaintenancePlan\DefineMaintenancePlan;
use Logistics\FleetOps\Application\Command\DefineMaintenancePlan\DefineMaintenancePlanHandler;
use Logistics\FleetOps\Application\Command\DefineVehicleProfile\DefineVehicleProfile;
use Logistics\FleetOps\Application\Command\DefineVehicleProfile\DefineVehicleProfileHandler;
use Logistics\FleetOps\Application\Command\RecordExpense\RecordExpense;
use Logistics\FleetOps\Application\Command\RecordExpense\RecordExpenseHandler;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometerHandler;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuel;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuelHandler;
use Logistics\FleetOps\Application\Command\RecordServiceDone\RecordServiceDone;
use Logistics\FleetOps\Application\Command\RecordServiceDone\RecordServiceDoneHandler;
use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDeparture;
use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDepartureHandler;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenanceHandler;
use Logistics\FleetOps\Application\Command\SetFuelPrice\SetFuelPrice;
use Logistics\FleetOps\Application\Command\SetFuelPrice\SetFuelPriceHandler;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenance;
use Logistics\FleetOps\Application\Command\StartMaintenance\StartMaintenanceHandler;
use Logistics\FleetOps\Application\Command\UpdateVehicleOdometer\UpdateVehicleOdometer;
use Logistics\FleetOps\Application\Command\UpdateVehicleOdometer\UpdateVehicleOdometerHandler;
use Logistics\FleetOps\Application\Query\GetCurrentFuelPrice\GetCurrentFuelPrice;
use Logistics\FleetOps\Application\Query\GetCurrentFuelPrice\GetCurrentFuelPriceHandler;
use Logistics\FleetOps\Application\Query\GetDailyFuelSpend\GetDailyFuelSpend;
use Logistics\FleetOps\Application\Query\GetDailyFuelSpend\GetDailyFuelSpendHandler;
use Logistics\FleetOps\Application\Query\GetDeparture\GetDeparture;
use Logistics\FleetOps\Application\Query\GetDeparture\GetDepartureHandler;
use Logistics\FleetOps\Application\Query\GetFuelConsumption\GetFuelConsumption;
use Logistics\FleetOps\Application\Query\GetFuelConsumption\GetFuelConsumptionHandler;
use Logistics\FleetOps\Application\Query\GetMaintenanceCost\GetMaintenanceCost;
use Logistics\FleetOps\Application\Query\GetMaintenanceCost\GetMaintenanceCostHandler;
use Logistics\FleetOps\Application\Query\GetMaintenanceOrder\GetMaintenanceOrder;
use Logistics\FleetOps\Application\Query\GetMaintenanceOrder\GetMaintenanceOrderHandler;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummaryHandler;
use Logistics\FleetOps\Application\Query\GetVehicleExpenseTotal\GetVehicleExpenseTotal;
use Logistics\FleetOps\Application\Query\GetVehicleExpenseTotal\GetVehicleExpenseTotalHandler;
use Logistics\FleetOps\Application\Query\GetVehicleProfile\GetVehicleProfile;
use Logistics\FleetOps\Application\Query\GetVehicleProfile\GetVehicleProfileHandler;
use Logistics\FleetOps\Application\Query\GetVehicleUtilization\GetVehicleUtilization;
use Logistics\FleetOps\Application\Query\GetVehicleUtilization\GetVehicleUtilizationHandler;
use Logistics\FleetOps\Application\Query\ListDepartures\ListDepartures;
use Logistics\FleetOps\Application\Query\ListDepartures\ListDeparturesHandler;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenance;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenanceHandler;
use Logistics\FleetOps\Application\Query\ListExpenses\ListExpenses;
use Logistics\FleetOps\Application\Query\ListExpenses\ListExpensesHandler;
use Logistics\FleetOps\Application\Query\ListFuelPrices\ListFuelPrices;
use Logistics\FleetOps\Application\Query\ListFuelPrices\ListFuelPricesHandler;
use Logistics\FleetOps\Application\Query\ListMaintenanceOrders\ListMaintenanceOrders;
use Logistics\FleetOps\Application\Query\ListMaintenanceOrders\ListMaintenanceOrdersHandler;
use Logistics\FleetOps\Application\Query\ListMaintenancePlans\ListMaintenancePlans;
use Logistics\FleetOps\Application\Query\ListMaintenancePlans\ListMaintenancePlansHandler;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadings;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadingsHandler;
use Logistics\FleetOps\Application\Query\ListRefuels\ListRefuels;
use Logistics\FleetOps\Application\Query\ListRefuels\ListRefuelsHandler;
use Logistics\FleetOps\Application\Query\ListVehicleProfiles\ListVehicleProfiles;
use Logistics\FleetOps\Application\Query\ListVehicleProfiles\ListVehicleProfilesHandler;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Infrastructure\Console\AlertDueMaintenanceCommand;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentDepartureRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentExpenseRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentFuelPriceRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentMaintenanceOrderRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentMaintenancePlanRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentOdometerRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentRefuelRepository;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository\EloquentVehicleProfileRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context FleetOps (operación y costos de la flota propia: combustible,
 * mantenimiento, salidas/cargas, odómetro, perfil técnico, gastos y TCO). Se registra a mano en
 * bootstrap/providers.php (el package está en dont-discover) para que el orden lo controle el proyecto.
 */
final class FleetOpsServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        // Combustible.
        SetFuelPrice::class => SetFuelPriceHandler::class,
        RecordRefuel::class => RecordRefuelHandler::class,
        ListFuelPrices::class => ListFuelPricesHandler::class,
        GetCurrentFuelPrice::class => GetCurrentFuelPriceHandler::class,
        ListRefuels::class => ListRefuelsHandler::class,
        GetFuelConsumption::class => GetFuelConsumptionHandler::class,
        GetDailyFuelSpend::class => GetDailyFuelSpendHandler::class,

        // Mantenimiento.
        ScheduleMaintenance::class => ScheduleMaintenanceHandler::class,
        StartMaintenance::class => StartMaintenanceHandler::class,
        CompleteMaintenance::class => CompleteMaintenanceHandler::class,
        CancelMaintenance::class => CancelMaintenanceHandler::class,
        DefineMaintenancePlan::class => DefineMaintenancePlanHandler::class,
        RecordServiceDone::class => RecordServiceDoneHandler::class,
        ActivatePlan::class => ActivatePlanHandler::class,
        DeactivatePlan::class => DeactivatePlanHandler::class,
        ListMaintenanceOrders::class => ListMaintenanceOrdersHandler::class,
        GetMaintenanceOrder::class => GetMaintenanceOrderHandler::class,
        ListMaintenancePlans::class => ListMaintenancePlansHandler::class,
        GetMaintenanceCost::class => GetMaintenanceCostHandler::class,
        ListDueMaintenance::class => ListDueMaintenanceHandler::class,

        // Salidas / despachos + odómetro.
        RegisterDeparture::class => RegisterDepartureHandler::class,
        CloseDeparture::class => CloseDepartureHandler::class,
        RecordOdometer::class => RecordOdometerHandler::class,
        ListDepartures::class => ListDeparturesHandler::class,
        GetDeparture::class => GetDepartureHandler::class,
        ListOdometerReadings::class => ListOdometerReadingsHandler::class,
        GetVehicleUtilization::class => GetVehicleUtilizationHandler::class,

        // Perfil técnico + gastos/TCO.
        DefineVehicleProfile::class => DefineVehicleProfileHandler::class,
        UpdateVehicleOdometer::class => UpdateVehicleOdometerHandler::class,
        RecordExpense::class => RecordExpenseHandler::class,
        ListVehicleProfiles::class => ListVehicleProfilesHandler::class,
        GetVehicleProfile::class => GetVehicleProfileHandler::class,
        ListExpenses::class => ListExpensesHandler::class,
        GetVehicleExpenseTotal::class => GetVehicleExpenseTotalHandler::class,
        GetVehicleCostSummary::class => GetVehicleCostSummaryHandler::class,
    ];

    /**
     * Puerto de repositorio → implementación Eloquent.
     *
     * @var array<class-string, class-string>
     */
    private const REPOSITORIES = [
        FuelPriceRepository::class => EloquentFuelPriceRepository::class,
        RefuelRepository::class => EloquentRefuelRepository::class,
        MaintenanceOrderRepository::class => EloquentMaintenanceOrderRepository::class,
        MaintenancePlanRepository::class => EloquentMaintenancePlanRepository::class,
        DepartureRepository::class => EloquentDepartureRepository::class,
        OdometerRepository::class => EloquentOdometerRepository::class,
        VehicleProfileRepository::class => EloquentVehicleProfileRepository::class,
        ExpenseRepository::class => EloquentExpenseRepository::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-fleetops.php', 'logistics-fleetops');

        foreach (self::REPOSITORIES as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AlertDueMaintenanceCommand::class]);

            // Alerta diaria de mantenimiento preventivo por vencer/vencido (→ outbox → supervisor).
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('logistics:fleetops:maintenance-alerts')
                    ->dailyAt('07:30')
                    ->withoutOverlapping();
            });
        }
    }
}
