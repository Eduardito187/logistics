<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrewHandler;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatusHandler;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklistHandler;
use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocument;
use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocumentHandler;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriverHandler;
use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelper;
use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelperHandler;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicleHandler;
use Logistics\Fleet\Application\Command\SetHelperAvailability\SetHelperAvailability;
use Logistics\Fleet\Application\Command\SetHelperAvailability\SetHelperAvailabilityHandler;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocuments;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocumentsHandler;
use Logistics\Fleet\Application\Query\ListDrivers\ListDrivers;
use Logistics\Fleet\Application\Query\ListDrivers\ListDriversHandler;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocumentsHandler;
use Logistics\Fleet\Application\Query\ListHelpers\ListHelpers;
use Logistics\Fleet\Application\Query\ListHelpers\ListHelpersHandler;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklists;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklistsHandler;
use Logistics\Fleet\Application\Query\ListVehicles\ListVehicles;
use Logistics\Fleet\Application\Query\ListVehicles\ListVehiclesHandler;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentCrewRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentDocumentRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentDriverRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentHelperRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentPreTripInspectionRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentVehicleRepository;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Flota y tripulación.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class FleetServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler que este contexto aporta al bus CQRS compartido.
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RegisterVehicle::class => RegisterVehicleHandler::class,
        ChangeVehicleStatus::class => ChangeVehicleStatusHandler::class,
        RegisterDriver::class => RegisterDriverHandler::class,
        RegisterHelper::class => RegisterHelperHandler::class,
        SetHelperAvailability::class => SetHelperAvailabilityHandler::class,
        AssembleCrew::class => AssembleCrewHandler::class,
        ConductPreTripChecklist::class => ConductPreTripChecklistHandler::class,
        RegisterDocument::class => RegisterDocumentHandler::class,
        ListVehicles::class => ListVehiclesHandler::class,
        ListDrivers::class => ListDriversHandler::class,
        ListHelpers::class => ListHelpersHandler::class,
        ListPreTripChecklists::class => ListPreTripChecklistsHandler::class,
        ListDocuments::class => ListDocumentsHandler::class,
        ListExpiringDocuments::class => ListExpiringDocumentsHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/fleet.php', 'fleet');

        $this->app->bind(VehicleRepository::class, EloquentVehicleRepository::class);
        $this->app->bind(DriverRepository::class, EloquentDriverRepository::class);
        $this->app->bind(HelperRepository::class, EloquentHelperRepository::class);
        $this->app->bind(CrewRepository::class, EloquentCrewRepository::class);
        $this->app->bind(PreTripInspectionRepository::class, EloquentPreTripInspectionRepository::class);
        $this->app->bind(DocumentRepository::class, EloquentDocumentRepository::class);

        // Dispatcher del outbox: ante un incidente MAYOR de camión (execution.vehicle_incident_reported),
        // deja el vehículo fuera de servicio. Lo compone el integrador en el relay por eventName.
        $this->app->bind(VehicleIncidentFleetDispatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
