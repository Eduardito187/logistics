<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRouteHandler;
use Logistics\Execution\Application\Command\AssignShelfLocation\AssignShelfLocation;
use Logistics\Execution\Application\Command\AssignShelfLocation\AssignShelfLocationHandler;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRouteHandler;
use Logistics\Execution\Application\Command\CaptureDeliveryEvidence\CaptureDeliveryEvidence;
use Logistics\Execution\Application\Command\CaptureDeliveryEvidence\CaptureDeliveryEvidenceHandler;
use Logistics\Execution\Application\Command\CloseDriverRoute\CloseDriverRoute;
use Logistics\Execution\Application\Command\CloseDriverRoute\CloseDriverRouteHandler;
use Logistics\Execution\Application\Command\MarkShipmentDeparted\MarkShipmentDeparted;
use Logistics\Execution\Application\Command\MarkShipmentDeparted\MarkShipmentDepartedHandler;
use Logistics\Execution\Application\Command\MarkStopArrived\MarkStopArrived;
use Logistics\Execution\Application\Command\MarkStopArrived\MarkStopArrivedHandler;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReceptionHandler;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNodeHandler;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReceptionHandler;
use Logistics\Execution\Application\Command\RecordEvidenceValidation\RecordEvidenceValidation;
use Logistics\Execution\Application\Command\RecordEvidenceValidation\RecordEvidenceValidationHandler;
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
use Logistics\Execution\Application\Command\ReportDeliveryIssue\ReportDeliveryIssue;
use Logistics\Execution\Application\Command\ReportDeliveryIssue\ReportDeliveryIssueHandler;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncident;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncidentHandler;
use Logistics\Execution\Application\Command\StartDriverRoute\StartDriverRoute;
use Logistics\Execution\Application\Command\StartDriverRoute\StartDriverRouteHandler;
use Logistics\Execution\Application\Contract\DeliveryEvidenceReader;
use Logistics\Execution\Application\Contract\DeliveryReferenceReader;
use Logistics\Execution\Application\Contract\EvidenceStorage;
use Logistics\Execution\Application\Contract\NodeReceptionListReader;
use Logistics\Execution\Application\Contract\NodeReceptionTargetReader;
use Logistics\Execution\Application\Contract\PlannedRouteStopsProvider;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Logistics\Execution\Application\Contract\ScanBatchLedger;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\Contract\ShipmentUnitProductReader;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;
use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidence;
use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidenceHandler;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryStateHandler;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRouteHandler;
use Logistics\Execution\Application\Query\GetDriverRouteLifecycle\GetDriverRouteLifecycle;
use Logistics\Execution\Application\Query\GetDriverRouteLifecycle\GetDriverRouteLifecycleHandler;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReception;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReceptionHandler;
use Logistics\Execution\Application\Query\GetRouteStop\GetRouteStop;
use Logistics\Execution\Application\Query\GetRouteStop\GetRouteStopHandler;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicleHandler;
use Logistics\Execution\Application\Query\ListNodeReceptions\ListNodeReceptions;
use Logistics\Execution\Application\Query\ListNodeReceptions\ListNodeReceptionsHandler;
use Logistics\Execution\Application\Query\ResolveNodeReceptionTarget\ResolveNodeReceptionTarget;
use Logistics\Execution\Application\Query\ResolveNodeReceptionTarget\ResolveNodeReceptionTargetHandler;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabel;
use Logistics\Execution\Application\Query\VerifyLabel\VerifyLabelHandler;
use Logistics\Execution\Domain\Contract\DeliveryEvidenceRepository;
use Logistics\Execution\Domain\Contract\DeliveryIssueRepository;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Contract\VehicleIncidentRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Infrastructure\Console\PruneScanEventsCommand;
use Logistics\Execution\Infrastructure\Labels\SodiumLabelVerificationGateway;
use Logistics\Execution\Infrastructure\Labels\StubLabelVerificationGateway;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryEvidenceRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryIssueRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentDeliveryRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentNodeReceptionRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleIncidentRepository;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\EloquentVehicleRouteRepository;
use Logistics\Execution\Infrastructure\ReadModel\DbDeliveryReferenceReader;
use Logistics\Execution\Infrastructure\ReadModel\DbNodeReceptionListReader;
use Logistics\Execution\Infrastructure\ReadModel\DbNodeReceptionTargetReader;
use Logistics\Execution\Infrastructure\ReadModel\DbPlannedRouteStopsProvider;
use Logistics\Execution\Infrastructure\ReadModel\DbRouteGeometryReader;
use Logistics\Execution\Infrastructure\ReadModel\DbScanBatchLedger;
use Logistics\Execution\Infrastructure\ReadModel\DbShipmentFulfillmentReader;
use Logistics\Execution\Infrastructure\ReadModel\DbShipmentUnitIdsReader;
use Logistics\Execution\Infrastructure\ReadModel\DbShipmentUnitProductReader;
use Logistics\Execution\Infrastructure\ReadModel\DbStopEnrichmentReader;
use Logistics\Execution\Infrastructure\ReadModel\DbVehicleFitnessReader;
use Logistics\Execution\Infrastructure\ReadModel\EloquentDeliveryEvidenceReader;
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
        MarkShipmentDeparted::class => MarkShipmentDepartedHandler::class,
        RecordUnloadScan::class => RecordUnloadScanHandler::class,
        RecordProofOfDelivery::class => RecordProofOfDeliveryHandler::class,
        RecordFailedDelivery::class => RecordFailedDeliveryHandler::class,
        RecordNodeReception::class => RecordNodeReceptionHandler::class,
        OpenNodeReception::class => OpenNodeReceptionHandler::class,
        ReceiveUnitAtNode::class => ReceiveUnitAtNodeHandler::class,
        ReconcileNodeReception::class => ReconcileNodeReceptionHandler::class,
        AssembleDailyRoute::class => AssembleDailyRouteHandler::class,
        AttachShipmentToRoute::class => AttachShipmentToRouteHandler::class,
        ReportVehicleIncident::class => ReportVehicleIncidentHandler::class,
        ReportDeliveryIssue::class => ReportDeliveryIssueHandler::class,
        CaptureDeliveryEvidence::class => CaptureDeliveryEvidenceHandler::class,
        RecordEvidenceValidation::class => RecordEvidenceValidationHandler::class,
        GetDeliveryEvidence::class => GetDeliveryEvidenceHandler::class,
        GetDeliveryState::class => GetDeliveryStateHandler::class,
        GetNodeReception::class => GetNodeReceptionHandler::class,
        GetDriverRoute::class => GetDriverRouteHandler::class,
        ListDeliveriesByVehicle::class => ListDeliveriesByVehicleHandler::class,
        VerifyLabel::class => VerifyLabelHandler::class,
    ];

    // ── Bloque field-flow: ciclo de la ruta del día, llegada a parada (KPIs) ──────────────────────────
    /**
     * @var array<class-string, class-string>
     */
    private const array FIELD_FLOW_HANDLERS = [
        StartDriverRoute::class => StartDriverRouteHandler::class,
        CloseDriverRoute::class => CloseDriverRouteHandler::class,
        MarkStopArrived::class => MarkStopArrivedHandler::class,
        GetDriverRouteLifecycle::class => GetDriverRouteLifecycleHandler::class,
        GetRouteStop::class => GetRouteStopHandler::class,
    ];
    // ── fin bloque field-flow ──

    // ── Bloque node-receptions: apertura derivada, listado, estante, conciliación con motivos ─────────
    /**
     * @var array<class-string, class-string>
     */
    private const array NODE_RECEPTION_HANDLERS = [
        ResolveNodeReceptionTarget::class => ResolveNodeReceptionTargetHandler::class,
        ListNodeReceptions::class => ListNodeReceptionsHandler::class,
        AssignShelfLocation::class => AssignShelfLocationHandler::class,
    ];
    // ── fin bloque node-receptions ──

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/logistics-execution.php', 'logistics-execution');

        $this->app->bind(DeliveryRepository::class, EloquentDeliveryRepository::class);
        $this->app->bind(NodeReceptionRepository::class, EloquentNodeReceptionRepository::class);
        $this->app->bind(VehicleRouteRepository::class, EloquentVehicleRouteRepository::class);
        $this->app->bind(VehicleIncidentRepository::class, EloquentVehicleIncidentRepository::class);
        $this->app->bind(DeliveryIssueRepository::class, EloquentDeliveryIssueRepository::class);
        // Evidencia anti-fraude ligada al cliente: repositorio (escritura) y reader (lectura CQRS).
        $this->app->bind(DeliveryEvidenceRepository::class, EloquentDeliveryEvidenceRepository::class);
        $this->app->bind(DeliveryEvidenceReader::class, EloquentDeliveryEvidenceReader::class);

        // Enriquecedores cross-context de la ruta del chofer (lectura directa, sin acoplar el dominio):
        // teléfono/referencia/líneas desde shipments y la geometría de la ruta desde el plan (planning).
        $this->app->bind(StopEnrichmentReader::class, static function ($app): StopEnrichmentReader {
            return new DbStopEnrichmentReader($app->make('db')->connection());
        });
        // Paradas del plan publicado para armar la ruta del día (el backend ensambla; la app no manda paradas).
        $this->app->bind(PlannedRouteStopsProvider::class, static function ($app): PlannedRouteStopsProvider {
            return new DbPlannedRouteStopsProvider($app->make('db')->connection());
        });
        // Referencia legible del envío (pedido/cliente/dirección) para las entregas del chofer: NUNCA UUID.
        $this->app->bind(DeliveryReferenceReader::class, static function ($app): DeliveryReferenceReader {
            return new DbDeliveryReferenceReader($app->make('db')->connection());
        });

        // ── Bloque field-flow ──────────────────────────────────────────────────────────────────────────
        // Bultos de cada envío (units_total / unit_ids) para paradas y entregas; libro de idempotencia por
        // ítem del lote de escaneos; aptitud del vehículo (checklist pre-viaje de Flota).
        $this->app->bind(ShipmentUnitIdsReader::class, static function ($app): ShipmentUnitIdsReader {
            return new DbShipmentUnitIdsReader($app->make('db')->connection());
        });
        $this->app->bind(ScanBatchLedger::class, static function ($app): ScanBatchLedger {
            return new DbScanBatchLedger($app->make('db')->connection());
        });
        $this->app->bind(VehicleFitnessReader::class, static function ($app): VehicleFitnessReader {
            return new DbVehicleFitnessReader($app->make('db')->connection());
        });
        // ── fin bloque field-flow ──
        $this->app->bind(RouteGeometryReader::class, static function ($app): RouteGeometryReader {
            // Si el plan existe sin geometría, se le pide a Planificación (su servicio de aplicación) que la
            // calcule y persista; Ejecución no escribe sobre Planning por sí misma.
            $refresher = 'Logistics\\Planning\\Application\\Service\\PlanGeometryRefresher';
            $ensure = class_exists($refresher)
                ? static function (string $vehicleId, string $date) use ($app, $refresher): bool {
                    $service = $app->make($refresher);

                    return is_object($service) && method_exists($service, 'ensureForVehicleAndDate')
                        && $service->ensureForVehicleAndDate($vehicleId, $date) === true;
                }
            : null;

            return new DbRouteGeometryReader($app->make('db')->connection(), $ensure);
        });

        // Lectura del fulfillment_mode del envío (desde shipments, read-only) para bifurcar la ejecución
        // dropship vs. OWNED/FBD sin acoplar el dominio al contexto shipments.
        $this->app->bind(ShipmentFulfillmentReader::class, static function ($app): ShipmentFulfillmentReader {
            return new DbShipmentFulfillmentReader($app->make('db')->connection());
        });

        // Producto de cada bulto (foto, nombre, SKU, N de M) para la recepción en nodo: lectura en lote de
        // shipment_units → shipment_lines, read-only.
        $this->app->bind(ShipmentUnitProductReader::class, static function ($app): ShipmentUnitProductReader {
            return new DbShipmentUnitProductReader($app->make('db')->connection());
        });

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

        // Bloque node-receptions (ver registerNodeReceptionReaders()).
        $this->registerNodeReceptionReaders();
    }

    // ── Bloque node-receptions ─────────────────────────────────────────────────────────────────────
    /**
     * Lecturas cross-context de la recepción en nodo (read-only, query builder): derivar destino/origen/
     * manifiesto al abrir con solo el envío, y el listado paginado por nodo y estado sin N+1.
     */
    private function registerNodeReceptionReaders(): void
    {
        $this->app->bind(NodeReceptionTargetReader::class, static function ($app): NodeReceptionTargetReader {
            return new DbNodeReceptionTargetReader($app->make('db')->connection());
        });
        $this->app->bind(NodeReceptionListReader::class, static function ($app): NodeReceptionListReader {
            return new DbNodeReceptionListReader($app->make('db')->connection());
        });
    }
    // ── fin bloque node-receptions ──

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
        // Bloque field-flow (ver FIELD_FLOW_HANDLERS).
        foreach (self::FIELD_FLOW_HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }
        // Bloque node-receptions (ver NODE_RECEPTION_HANDLERS).
        foreach (self::NODE_RECEPTION_HANDLERS as $message => $handler) {
            $registry->register($message, $handler);
        }

        // Comando de poda de retención de escaneos (C7). No se agenda aquí: la retención es manual/ops.
        if ($this->app->runningInConsole()) {
            $this->commands([PruneScanEventsCommand::class]);
        }
    }
}
