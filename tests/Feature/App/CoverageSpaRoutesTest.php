<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Cobertura de la app web (SPA Inertia) bajo /app: ejercita los controllers de solo lectura (índices y
 * detalles) y el concern ResolvesRouteMapData. Cada endpoint se golpea por su ruta real como admin; los
 * detalles usan findOrFail, así que siembran un registro con las factories de cada contexto. El layout
 * resuelve @vite, pero TestCase ya llama withoutVite(), así que el render no necesita assets compilados.
 */
beforeEach(function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza los índices del SPA sin datos (tolerante a tablas vacías)', function (string $url): void {
    $this->get($url)->assertOk();
})->with([
    'dashboard' => ['/app'],
    'deliveries' => ['/app/deliveries'],
    'shipments' => ['/app/shipments'],
    'tracking' => ['/app/tracking'],
    'routes' => ['/app/routes'],
    'personnel' => ['/app/personnel'],
    'planning' => ['/app/planning'],
    'control-tower' => ['/app/control-tower'],
]);

it('renderiza el detalle de una entrega', function (): void {
    $delivery = DeliveryRecord::factory()->delivered()->create();

    $this->get('/app/deliveries/'.$delivery->getKey())->assertOk();
});

it('renderiza el detalle de un envío', function (): void {
    $shipment = ShipmentRecord::factory()->create();

    $this->get('/app/shipments/'.$shipment->getKey())->assertOk();
});

it('renderiza el detalle de un rastreo', function (): void {
    $tracking = TrackingRecord::factory()->create();

    $this->get('/app/tracking/'.$tracking->getKey())->assertOk();
});

it('renderiza el detalle y el tablero de un plan', function (): void {
    $plan = PlanRecordFactory::new()->withStops(2)->create();

    $this->get('/app/planning/'.$plan->getKey())->assertOk();
    $this->get('/app/planning/'.$plan->getKey().'/board')->assertOk();
});

it('renderiza el tablero del día de planificación', function (): void {
    PlanRecordFactory::new()->onDate('2026-10-15')->withStops(2)->create();

    $this->get('/app/planning/plans/day/2026-10-15')->assertOk();
});

it('renderiza el detalle de un chofer', function (): void {
    $driver = DriverFactory::new()->create();

    $this->get('/app/personnel/drivers/'.$driver->getKey())->assertOk();
});

it('renderiza el detalle de un ayudante', function (): void {
    $helper = HelperFactory::new()->create();

    $this->get('/app/personnel/helpers/'.$helper->getKey())->assertOk();
});
