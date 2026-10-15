<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRouteHandler;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReceptionHandler;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNodeHandler;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReceptionHandler;
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
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryStateHandler;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRouteHandler;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReception;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReceptionHandler;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicleHandler;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabel;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabelHandler;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Contract\VehicleIncidentRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Infrastructure\Labels\SodiumLabelVerificationGateway;
use Logistics\Execution\Infrastructure\Labels\StubLabelVerificationGateway;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentNodeReceptionRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleIncidentRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleRouteRepository;
use Logistics\Execution\Infrastructure\Storage\FilesystemEvidenceStorage;
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
        OpenNodeReception::class => OpenNodeReceptionHandler::class,
        ReceiveUnitAtNode::class => ReceiveUnitAtNodeHandler::class,
        ReconcileNodeReception::class => ReconcileNodeReceptionHandler::class,
        AssembleDailyRoute::class => AssembleDailyRouteHandler::class,
        ReportVehicleIncident::class => ReportVehicleIncidentHandler::class,
        GetDeliveryState::class => GetDeliveryStateHandler::class,
        GetNodeReception::class => GetNodeReceptionHandler::class,
        GetDriverRoute::class => GetDriverRouteHandler::class,
        ListDeliveriesByVehicle::class => ListDeliveriesByVehicleHandler::class,
        VerifyLabel::class => VerifyLabelHandler::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-execution.php', 'logistics-execution');

        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(NodeReceptionRepository::class, EloquentNodeReceptionRepository::class);
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

        // Subida de evidencias (prueba de entrega y daños) a los discos S3-compatible de la plataforma,
        // con reintento. Detrás de la puerta de Application EvidenceStorage para no acoplar la
        // Presentation al almacenamiento.
        $this->app->bind(EvidenceStorage::class, function ($app): EvidenceStorage {
            $config = $app->make('config');

            /** @var array<string, string> $disks */
            $disks = (array) $config->get('logistics-execution.evidence_disks', ['pod' => 'pod', 'damage' => 'damage']);
            $attempts = (int) $config->get('logistics-execution.evidence_upload_attempts', 3);

            return new FilesystemEvidenceStorage(array_map('strval', $disks), $attempts);
        });

        // Verificación de etiqueta firmada offline (P1-EXE-12). Si hay claves públicas Ed25519 cargadas
        // en config, se usa el verificador REAL (SodiumLabelVerificationGateway); si no, cae al stub
        // (valida forma + id de clave conocido) para poder operar sin claves. El dominio y la aplicación
        // no cambian: ambos están detrás del mismo contrato.
        $this->app->bind(LabelVerificationGateway::class, function ($app): LabelVerificationGateway {
            $config = $app->make('config');

            /** @var array<int, string> $publicKeys */
            $publicKeys = (array) $config->get('logistics-execution.label_public_keys', []);
            $decoded = self::decodePublicKeys($publicKeys);

            if ($decoded !== []) {
                return new SodiumLabelVerificationGateway($decoded);
            }

            /** @var list<int> $knownKeyIds */
            $knownKeyIds = (array) $config->get('logistics-execution.label_known_key_ids', [1]);

            return new StubLabelVerificationGateway(array_values(array_map('intval', $knownKeyIds)));
        });
    }

    /**
     * Decodifica las claves públicas de etiquetas de base64 a bytes crudos. Descarta las que no decodifican
     * para no voltear el arranque por una clave mal cargada: el gateway igual valida su longitud.
     *
     * @param  array<int|string, mixed> $publicKeys id de clave => clave pública en base64
     * @return array<int, string>
     */
    private static function decodePublicKeys(array $publicKeys): array
    {
        $decoded = [];
        foreach ($publicKeys as $id => $base64) {
            if (!is_string($base64) || trim($base64) === '') {
                continue;
            }

            $raw = base64_decode(trim($base64), true);
            if ($raw !== false && $raw !== '') {
                $decoded[(int) $id] = $raw;
            }
        }

        return $decoded;
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
