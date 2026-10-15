<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionUnitRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Execution\Presentation\Filament\Pages\LastMileBoard;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages\ListDeliveries;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages\ViewDelivery;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages\ListNodeReceptions;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages\ViewNodeReception;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages\ListScanEvents;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages\ViewScanEvent;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages\ListVehicleRoutes;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages\ViewVehicleRoute;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de render de la capa de Presentación (Filament) del contexto Ejecución.
 *
 * Montar cada página Livewire ejecuta las definiciones de table()/infolist()/schema() de los Resources
 * y el getViewData() del cockpit, sin HTTP. No caza bugs: ejercita el código real de presentación con
 * datos sembrados por las factories del contexto, tolerando tablas vacías donde aplica.
 */
beforeEach(function (): void {
    // Los Resources/Pages resuelven el panel actual para render; en pruebas se fija a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

// ── DeliveryResource ────────────────────────────────────────────────────────

it('renderiza el listado de entregas con filas de varios estados', function (): void {
    $delivered = DeliveryRecord::factory()->delivered()->create();
    DeliveryRecord::factory()->failed()->create();
    DeliveryRecord::factory()->create();

    // Un par de escaneos de la entrega entregada, para ejercitar las columnas/relaciones.
    ScanEventRecord::factory()->ofType(ScanType::Preparation)->forDelivery($delivered->id)->create();
    ScanEventRecord::factory()->ofType(ScanType::ProofOfDelivery)->forDelivery($delivered->id)->create();

    Livewire::test(ListDeliveries::class)->assertOk();
});

it('renderiza la vista de una entrega con sus escaneos', function (): void {
    $delivery = DeliveryRecord::factory()->delivered()->create();
    foreach (ScanType::cases() as $type) {
        ScanEventRecord::factory()->ofType($type)->forDelivery($delivery->id)->create();
    }

    Livewire::test(ViewDelivery::class, ['record' => $delivery->id])->assertOk();
});

// ── NodeReceptionResource ─────────────────────────────────────────────────────

it('renderiza el listado de recepciones en nodo con recibidos/faltantes/sobrantes', function (): void {
    $reception = NodeReceptionRecord::factory()->create();
    // Un recibido esperado y un recibido sobrante (no esperado), para los conteos.
    NodeReceptionUnitRecord::factory()->forReception($reception->id)->create([
        'unit_id' => $reception->expected_unit_ids[0],
    ]);
    NodeReceptionUnitRecord::factory()->forReception($reception->id)->create([
        'unit_id' => 'U-SURPLUS-1',
    ]);

    Livewire::test(ListNodeReceptions::class)->assertOk();
});

it('renderiza la vista de una recepción en nodo', function (): void {
    $reception = NodeReceptionRecord::factory()->create();
    NodeReceptionUnitRecord::factory()->forReception($reception->id)->create([
        'unit_id' => $reception->expected_unit_ids[0],
    ]);

    Livewire::test(ViewNodeReception::class, ['record' => $reception->id])->assertOk();
});

// ── ScanEventResource ──────────────────────────────────────────────────────────

it('renderiza el listado de escaneos de terreno', function (): void {
    $delivery = DeliveryRecord::factory()->create();
    foreach (ScanType::cases() as $type) {
        ScanEventRecord::factory()->ofType($type)->forDelivery($delivery->id)->create();
    }

    Livewire::test(ListScanEvents::class)->assertOk();
});

it('renderiza la vista de un escaneo de terreno', function (): void {
    $delivery = DeliveryRecord::factory()->create();
    $scan = ScanEventRecord::factory()->ofType(ScanType::Load)->forDelivery($delivery->id)->create();

    Livewire::test(ViewScanEvent::class, ['record' => $scan->id])->assertOk();
});

// ── VehicleRouteResource ───────────────────────────────────────────────────────

/**
 * Arma una ruta del día (dos paradas con coordenadas) por el CommandBus y devuelve su registro, para
 * poder renderizar la vista de solo lectura con datos reales (mapa, paradas y progreso).
 */
function coverageAssembleRoute(): VehicleRouteRecord
{
    $vehicleId = Uuid::generate()->value;

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: '2026-10-13',
        stops: [
            new RouteStopInput(
                shipmentId: Uuid::generate()->value,
                sequence: 1,
                orderReference: 'ORD-1',
                customerName: 'Cliente Uno',
                address: 'Av. Siempre Viva 1',
                etaAt: '2026-10-13T09:00:00+00:00',
                lat: -16.5,
                lng: -68.15,
            ),
            new RouteStopInput(
                shipmentId: Uuid::generate()->value,
                sequence: 2,
                orderReference: 'ORD-2',
                customerName: 'Cliente Dos',
                address: 'Av. Siempre Viva 2',
                etaAt: '2026-10-13T10:00:00+00:00',
                lat: -16.51,
                lng: -68.16,
            ),
        ],
    ));

    return VehicleRouteRecord::query()->where('vehicle_id', $vehicleId)->firstOrFail();
}

it('renderiza el listado de rutas del chofer', function (): void {
    coverageAssembleRoute();

    Livewire::test(ListVehicleRoutes::class)->assertOk();
});

it('renderiza la vista de una ruta del chofer con su mapa y paradas', function (): void {
    $route = coverageAssembleRoute();

    Livewire::test(ViewVehicleRoute::class, ['record' => $route->id])->assertOk();
});

// ── LastMileBoard (cockpit de última milla) ─────────────────────────────────────

it('renderiza el tablero de última milla con la cola del día por estado', function (): void {
    DeliveryRecord::factory()->create();
    DeliveryRecord::factory()->delivered()->create();
    DeliveryRecord::factory()->failed()->create();

    Livewire::test(LastMileBoard::class)->assertOk();
});
