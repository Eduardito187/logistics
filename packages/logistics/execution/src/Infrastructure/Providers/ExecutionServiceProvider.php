<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRouteHandler;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDeliveryHandler;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScanHandler;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReception;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReceptionHandler;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScanHandler;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDeliveryHandler;
use Logistics\Execution\Application\Command\RecordUnloadScan\RecordUnloadScan;
use Logistics\Execution\Application\Command\RecordUnloadScan\RecordUnloadScanHandler;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncident;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncidentHandler;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryStateHandler;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRouteHandler;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicleHandler;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabel;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabelHandler;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\Contract\VehicleIncidentRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Infrastructure\Labels\StubLabelVerificationGateway;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleIncidentRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleRouteRepository;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * Registro del bounded context Ejecución.
 *
 * Se registra explícitamente en bootstrap/providers.php (el package está en dont-discover)
 * para que el orden de registro lo controle siempre el proyecto.
 */
final class ExecutionServiceProvider extends ServiceProvider
{
    /**
     * Pares mensaje → handler del contexto (comandos y consultas).
     *
     * @var array<class-string, class-string>
     */
    private const HANDLERS = [
        RecordPreparationScan::class => RecordPreparationScanHandler::class,
        RecordLoadScan::class => RecordLoadScanHandler::class,
        RecordUnloadScan::class => RecordUnloadScanHandler::class,
        RecordProofOfDelivery::class => RecordProofOfDeliveryHandler::class,
        RecordFailedDelivery::class => RecordFailedDeliveryHandler::class,
        RecordNodeReception::class => RecordNodeReceptionHandler::class,
        AssembleDailyRoute::class => AssembleDailyRouteHandler::class,
        ReportVehicleIncident::class => ReportVehicleIncidentHandler::class,
        GetDeliveryState::class => GetDeliveryStateHandler::class,
        GetDriverRoute::class => GetDriverRouteHandler::class,
        ListDeliveriesByVehicle::class => ListDeliveriesByVehicleHandler::class,
        VerifyLabel::class => VerifyLabelHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-execution.php', 'logistics-execution');

        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(VehicleRouteRepository::class, EloquentVehicleRouteRepository::class);
        $this->app->bind(VehicleIncidentRepository::class, EloquentVehicleIncidentRepository::class);

        // El handler de incidentes necesita los minutos de retraso (incidente MENOR) de la config, que
        // no se autoinyectan como primitivo: se arma acá leyéndolos del contexto.
        $this->app->bind(ReportVehicleIncidentHandler::class, function ($app): ReportVehicleIncidentHandler {
            $minutes = (int) $app->make('config')->get('logistics-execution.incident.minor_delay_minutes', 120);

            return new ReportVehicleIncidentHandler(
                $app->make(VehicleRouteRepository::class),
                $app->make(VehicleIncidentRepository::class),
                $app->make(RecordDomainEvents::class),
                $minutes,
            );
        });

        // Verificación de etiqueta firmada offline (P1-EXE-12). Por defecto, el stub detrás del
        // contrato; el integrador lo reemplaza por el adapter real que delega en el verificador de
        // Labels sin tocar el dominio ni la aplicación.
        $this->app->bind(LabelVerificationGateway::class, function ($app): LabelVerificationGateway {
            /** @var list<int> $knownKeyIds */
            $knownKeyIds = (array) $app->make('config')->get('logistics-execution.label_known_key_ids', [1]);

            return new StubLabelVerificationGateway(array_values(array_map('intval', $knownKeyIds)));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');

        // Vistas Blade del contexto (p. ej. el mapa de la ruta en el admin), bajo el namespace
        // `logistics-execution::`.
        $this->loadViewsFrom(__DIR__.'/../../../resources/views', 'logistics-execution');

        /** @var HandlerRegistry $registry */
        $registry = $this->app->make(HandlerRegistry::class);
        foreach (self::HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
    }
}
