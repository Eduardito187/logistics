<?php

declare(strict_types=1);

use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\RouteStopRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleIncidentRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistory;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklists;
use Logistics\Fleet\Application\ReadModel\DriverHistoryView;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Database\Factories\CrewFactory;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\FleetDocumentFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\PreTripInspectionFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del lado de lectura (CQRS) del contexto fleet (Patrón C del brief): los Query handlers y sus
 * ReadModels sin cubrir se ejercitan sembrando filas reales y despachando por el QueryBus. El caso
 * estrella es GetDriverHistory, que cruza fleet_crews con las rutas de reparto de Execution.
 *
 * No busca cazar bugs: solo recorre el happy-path de cada consulta con datos verosímiles.
 */

// --- GetDriverHistory ------------------------------------------------------------------------------

it('arma el historial operativo del chofer cruzando tripulaciones y rutas de reparto', function (): void {
    $driver = DriverFactory::new()->create();
    $vehicle = VehicleFactory::new()->create();
    $helper = HelperFactory::new()->create();

    $driverId = (string) $driver->getKey();
    $vehicleId = (string) $vehicle->getKey();
    $helperId = (string) $helper->getKey();

    // Tripulación del chofer ese día, con un ayudante y ese camión.
    CrewFactory::new()
        ->forDriver($driverId)
        ->forVehicle($vehicleId)
        ->withHelpers([$helperId])
        ->assembledFor('2026-10-10')
        ->create();

    // Ruta de reparto confirmada de ese chofer/camión ese día.
    $routeId = Uuid::generate()->value;
    VehicleRouteRecord::query()->create([
        'id' => $routeId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'helper_ids' => [$helperId],
        'route_date' => '2026-10-10',
        'status' => 'completed',
    ]);

    // Tres paradas: una entregada, una fallida (con motivo) y una reprogramada.
    $failedShipmentId = Uuid::generate()->value;
    RouteStopRecord::query()->create([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'shipment_id' => Uuid::generate()->value,
        'sequence' => 1,
        'status' => 'COMPLETED',
    ]);
    RouteStopRecord::query()->create([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'shipment_id' => $failedShipmentId,
        'sequence' => 2,
        'status' => 'FAILED',
    ]);
    RouteStopRecord::query()->create([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'shipment_id' => Uuid::generate()->value,
        'sequence' => 3,
        'status' => 'RESCHEDULED',
    ]);

    // Motivo de fallo de la parada fallida.
    DeliveryRecord::query()->create([
        'id' => Uuid::generate()->value,
        'shipment_id' => $failedShipmentId,
        'vehicle_id' => $vehicleId,
        'status' => 'FAILED',
        'failure_reason' => 'CUSTOMER_ABSENT',
    ]);

    // Incidente con fecha de reprogramación para esa ruta.
    VehicleIncidentRecord::query()->create([
        'id' => Uuid::generate()->value,
        'route_id' => $routeId,
        'vehicle_id' => $vehicleId,
        'severity' => 'medium',
        'type' => 'breakdown',
        'affected_shipment_ids' => [],
        'reschedule_date' => '2026-10-11',
        'occurred_at' => '2026-10-10 12:00:00',
    ]);

    /** @var DriverHistoryView $view */
    $view = app(QueryBus::class)->ask(new GetDriverHistory($driverId, '2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(DriverHistoryView::class);

    $data = $view->toArray();
    expect($data['driver_id'])->toBe($driverId)
        ->and($data['driver_name'])->toBe($driver->name)
        ->and($data['totals']['days_worked'])->toBe(1)
        ->and($data['totals']['total_stops'])->toBe(3)
        ->and($data['totals']['delivered'])->toBe(1)
        ->and($data['totals']['failed'])->toBe(1)
        ->and($data['totals']['rescheduled'])->toBe(1)
        ->and($data['totals']['failed_by_reason'])->toBe(['CUSTOMER_ABSENT' => 1])
        ->and($data['totals']['vehicles'])->toContain($vehicle->plate)
        ->and($data['days'])->toHaveCount(1)
        ->and($data['days'][0]['helper_names'])->toContain($helper->name)
        ->and($data['days'][0]['reschedule_reasons'])->toContain('breakdown');
});

it('devuelve un historial vacío cuando el chofer no tiene tripulaciones en el rango', function (): void {
    $driver = DriverFactory::new()->create();

    /** @var DriverHistoryView $view */
    $view = app(QueryBus::class)->ask(
        new GetDriverHistory((string) $driver->getKey(), '2026-10-01', '2026-10-31'),
    );

    $data = $view->toArray();
    expect($data['totals']['days_worked'])->toBe(0)
        ->and($data['totals']['total_stops'])->toBe(0)
        ->and($data['days'])->toBe([]);
});

// --- ListExpiringDocuments -------------------------------------------------------------------------

it('lista los documentos próximos a vencer de vehículos, choferes, ayudantes y documentos de flota', function (): void {
    $vehicle = VehicleFactory::new()->withExpiredDocument()->create();
    DriverFactory::new()->withExpiredLicense()->create();
    HelperFactory::new()->create();
    FleetDocumentFactory::new()
        ->forOwner(DocumentOwnerType::Vehicle, (string) $vehicle->getKey())
        ->expired()
        ->create();

    /** @var list<ExpiringDocumentView> $results */
    $results = app(QueryBus::class)->ask(new ListExpiringDocuments(days: 60, referenceDate: '2026-10-14'));

    // Hay al menos el documento vencido del vehículo y la licencia vencida del chofer.
    expect($results)->not->toBeEmpty();
});

// --- ListPreTripChecklists -------------------------------------------------------------------------

it('lista todas las inspecciones pre-salida', function (): void {
    PreTripInspectionFactory::new()->create();
    PreTripInspectionFactory::new()->failed()->create();

    /** @var list<PreTripChecklistView> $all */
    $all = app(QueryBus::class)->ask(new ListPreTripChecklists);

    expect($all)->toHaveCount(2);
});

it('filtra las inspecciones pre-salida por vehículo y por aprobadas', function (): void {
    $vehicle = VehicleFactory::new()->create();
    $vehicleId = (string) $vehicle->getKey();

    PreTripInspectionFactory::new()->forVehicle($vehicleId)->create();
    PreTripInspectionFactory::new()->forVehicle($vehicleId)->failed()->create();
    PreTripInspectionFactory::new()->create();

    $byVehicle = app(QueryBus::class)->ask(new ListPreTripChecklists(vehicleId: $vehicleId));
    expect($byVehicle)->toHaveCount(2);

    $onlyPassed = app(QueryBus::class)->ask(new ListPreTripChecklists(vehicleId: $vehicleId, onlyPassed: true));
    expect($onlyPassed)->toHaveCount(1);
});
