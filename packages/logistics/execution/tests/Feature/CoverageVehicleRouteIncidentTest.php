<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleIncidentRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages\ViewVehicleRoute;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de la acción "Report incident" de la vista de ruta del chofer (ViewVehicleRoute), que el
 * render de la ficha no ejecuta.
 *
 * El flujo por routeId (resolución de la ruta por su id + validación de pertenencia al vehículo) y la
 * reacción MAYOR (ruta suspendida, paradas pendientes reprogramadas al día siguiente) son ramas que el
 * endpoint HTTP de incidente no recorre (ese usa la ruta abierta vigente y gravedad MENOR). Montar la
 * acción corre su schema (gravedad/tipo/nota) y su cierre de visibilidad; llamarla despacha el comando
 * real por el CommandBus y ejercita ReportVehicleIncidentHandler y el agregado VehicleIncident. No caza
 * bugs: recorre el happy-path real de la acción contra logistics_test.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma una ruta del día con dos paradas pendientes por el CommandBus y devuelve su registro, para poder
 * reportar un incidente sobre ella (requiere paradas pendientes que reprogramar).
 */
function coverageRouteWithPendingStops(): VehicleRouteRecord
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

it('monta la accion de reporte de incidente sobre una ruta con paradas pendientes', function (): void {
    $route = coverageRouteWithPendingStops();

    // Montar corre el schema (selects de gravedad/tipo desde los enums del dominio + nota) y el cierre
    // de visibilidad (hasPendingStops).
    Livewire::test(ViewVehicleRoute::class, ['record' => $route->id])
        ->mountAction('reportIncident')
        ->assertOk();
});

it('reporta un incidente mayor y reprograma las paradas pendientes', function (): void {
    $route = coverageRouteWithPendingStops();

    // Llamar la acción despacha ReportVehicleIncident por el routeId de la ruta: recorre la resolución
    // de ruta por id, la rama MAYOR (reprogramación al día siguiente) y el alta del incidente.
    Livewire::test(ViewVehicleRoute::class, ['record' => $route->id])
        ->callAction('reportIncident', data: [
            'severity' => IncidentSeverity::Major->value,
            'type' => IncidentType::Breakdown->value,
            'note' => 'Avería grave en ruta',
        ])
        ->assertHasNoActionErrors();

    // El hecho inmutable del incidente quedó registrado para esta ruta.
    expect(VehicleIncidentRecord::query()->where('route_id', $route->id)->exists())->toBeTrue();
});
