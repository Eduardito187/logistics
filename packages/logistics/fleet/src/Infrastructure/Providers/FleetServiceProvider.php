<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocuments;
use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocumentsHandler;
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
use Logistics\Fleet\Application\Command\UpdateDriverProfile\UpdateDriverProfile;
use Logistics\Fleet\Application\Command\UpdateDriverProfile\UpdateDriverProfileHandler;
use Logistics\Fleet\Application\Command\UpdateHelperProfile\UpdateHelperProfile;
use Logistics\Fleet\Application\Command\UpdateHelperProfile\UpdateHelperProfileHandler;
use Logistics\Fleet\Application\Command\UpdateVehicleProfile\UpdateVehicleProfile;
use Logistics\Fleet\Application\Command\UpdateVehicleProfile\UpdateVehicleProfileHandler;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistory;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistoryHandler;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrew;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrewHandler;
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
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentCrewRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentDocumentRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentDriverRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentHelperRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentPreTripInspectionRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository\EloquentVehicleRepository;
use Logistics\Fleet\Infrastructure\Settings\SettingsDocumentExpiryPolicy;
use Logistics\Fleet\Infrastructure\Settings\SettingsShiftRulesPolicy;
use Logistics\Fleet\Presentation\Console\AlertExpiringDocumentsCommand;
use Logistics\Shared\Application\HandlerRegistry;
use Logistics\Shared\Application\Query\QueryBus;

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
        UpdateDriverProfile::class => UpdateDriverProfileHandler::class,
        UpdateHelperProfile::class => UpdateHelperProfileHandler::class,
        UpdateVehicleProfile::class => UpdateVehicleProfileHandler::class,
        AssembleCrew::class => AssembleCrewHandler::class,
        ConductPreTripChecklist::class => ConductPreTripChecklistHandler::class,
        RegisterDocument::class => RegisterDocumentHandler::class,
        ListVehicles::class => ListVehiclesHandler::class,
        ListDrivers::class => ListDriversHandler::class,
        ListHelpers::class => ListHelpersHandler::class,
        ListPreTripChecklists::class => ListPreTripChecklistsHandler::class,
        ListDocuments::class => ListDocumentsHandler::class,
        ListExpiringDocuments::class => ListExpiringDocumentsHandler::class,
        GetDriverHistory::class => GetDriverHistoryHandler::class,
        GetMyCrew::class => GetMyCrewHandler::class,
        AlertExpiringDocuments::class => AlertExpiringDocumentsHandler::class,
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

        // Política de ventana de alerta de vencimiento: la lee de settings (versionado) por el QueryBus,
        // con respaldo por config (fleet.expiry_alert_days). Así el umbral es configurable sin tocar código.
        $this->app->bind(DocumentExpiryPolicy::class, function ($app): SettingsDocumentExpiryPolicy {
            return new SettingsDocumentExpiryPolicy(
                $app->make(QueryBus::class),
                (int) $app['config']->get('fleet.expiry_alert_days', 30),
            );
        });

        // Reglas de turnos y descansos: respaldo por config (fleet.shift), sobreescribible por settings
        // (versionado) vía el QueryBus. Se validan al armar una tripulación con turno.
        $this->app->bind(ShiftRulesPolicy::class, function ($app): SettingsShiftRulesPolicy {
            /** @var array{max_daily_hours: float|int, earliest_start_hour: float|int, latest_end_hour: float|int, min_rest_hours: float|int} $shift */
            $shift = $app['config']->get('fleet.shift', []);

            return new SettingsShiftRulesPolicy(
                $app->make(QueryBus::class),
                ShiftRules::fromHours(
                    (float) ($shift['max_daily_hours'] ?? 10),
                    (float) ($shift['earliest_start_hour'] ?? 5),
                    (float) ($shift['latest_end_hour'] ?? 22),
                    (float) ($shift['min_rest_hours'] ?? 10),
                ),
            );
        });

        // Dispatcher del outbox: ante un incidente MAYOR de camión (execution.vehicle_incident_reported),
        // deja el vehículo fuera de servicio. Lo compone el integrador en el relay por eventName.
        $this->app->bind(VehicleIncidentFleetDispatcher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
        // Vistas blade del admin del contexto (p. ej. la página de historial del chofer).
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-fleet');

        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        // Barrido programado de documentos por vencer / vencidos (lo agenda routes/console.php).
        if ($this->app->runningInConsole()) {
            $this->commands([AlertExpiringDocumentsCommand::class]);
        }
    }
}
