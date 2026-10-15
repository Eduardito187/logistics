<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\PingFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\TripFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\VehiclePositionFactory;
use Logistics\Telemetry\Presentation\Filament\Pages\LiveFleetMap;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\CreateGeofence;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\EditGeofence;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages\ListGeofences;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages\ListTelemetryAlerts;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages\ViewTelemetryAlert;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages\ListTrips;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages\ViewTrip;

/**
 * Cobertura de la capa Presentation de telemetría: montar cada página Livewire ejecuta las definiciones
 * de table()/form()/infolist()/schema() de los Resources y la Page del mapa en vivo, con datos reales
 * sembrados por las fábricas del contexto. Patrón A del brief de cobertura. No caza bugs: ejercita código.
 */
beforeEach(function (): void {
    // Los Resources/Pages resuelven el panel actual al renderizar; fuera de HTTP hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado de geocercas con datos', function (): void {
    GeofenceFactory::new()->ofType(GeofenceType::ForbiddenZone)->create();
    GeofenceFactory::new()->inactive()->create();

    Livewire::test(ListGeofences::class)->assertOk();
});

it('renderiza el alta de geocerca (schema del formulario)', function (): void {
    Livewire::test(CreateGeofence::class)->assertOk();
});

it('renderiza la edición de una geocerca', function (): void {
    $geofence = GeofenceFactory::new()->create();

    Livewire::test(EditGeofence::class, ['record' => $geofence->getKey()])->assertOk();
});

it('renderiza el listado de trips con datos', function (): void {
    TripFactory::new()->create();
    TripFactory::new()->active()->create();

    Livewire::test(ListTrips::class)->assertOk();
});

it('renderiza la vista de detalle de un trip', function (): void {
    $trip = TripFactory::new()->create();

    Livewire::test(ViewTrip::class, ['record' => $trip->getKey()])->assertOk();
});

it('renderiza el listado de alertas con datos', function (): void {
    AlertFactory::new()->ofType(AlertType::Speeding, AlertSeverity::Critical)->create();
    AlertFactory::new()->acknowledged()->create();

    Livewire::test(ListTelemetryAlerts::class)->assertOk();
});

it('renderiza la vista de detalle de una alerta', function (): void {
    $alert = AlertFactory::new()->create();

    Livewire::test(ViewTelemetryAlert::class, ['record' => $alert->getKey()])->assertOk();
});

it('renderiza el cockpit del mapa de flota en vivo', function (): void {
    VehiclePositionFactory::new()->stopped()->create();
    VehiclePositionFactory::new()->create();

    Livewire::test(LiveFleetMap::class)->assertOk();
});

it('refresca posiciones y excepciones del mapa en vivo por polling', function (): void {
    VehiclePositionFactory::new()->stopped()->create();
    AlertFactory::new()->create();

    Livewire::test(LiveFleetMap::class)
        ->call('positions')
        ->call('exceptions')
        ->assertOk();
});

it('devuelve el recorrido del día de un vehículo desde el mapa en vivo', function (): void {
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeff';
    PingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('today +1 hour'))->create();

    Livewire::test(LiveFleetMap::class)
        ->call('vehicleTrack', $vehicleId)
        ->assertOk();
});

it('reconoce una alerta sin reconocer desde el mapa en vivo', function (): void {
    $alert = AlertFactory::new()->create();

    Livewire::test(LiveFleetMap::class)
        ->call('acknowledge', (string) $alert->getKey())
        ->assertOk();
});
