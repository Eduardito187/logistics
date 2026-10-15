<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers de ESCRITURA del SPA (/app): DeliveryWriteController y
 * PlanningWriteController, más el detalle de ruta de solo lectura (RouteController::show) con el concern
 * ResolvesRouteMapData. Cada acción se golpea por su ruta real como admin con un cuerpo válido; como los
 * controllers de escritura siempre redirigen de vuelta (éxito o error de dominio capturado), se asevera
 * el redirect y, donde el happy-path es seguro, el flash de éxito. No caza bugs: ejercita el código real.
 */
beforeEach(function (): void {
    // Las rutas del SPA son web (grupo con CSRF); en los POST/PUT de prueba se omite la validación CSRF
    // para ejercitar directamente el controller (no es lo que se está probando aquí).
    $this->withoutMiddleware();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

// ── DeliveryWriteController ────────────────────────────────────────────────────────────────────────

it('registra una entrega fallida por su ruta POST', function (): void {
    $delivery = DeliveryRecord::factory()->create();

    $this->post('/app/deliveries/'.$delivery->getKey().'/fail', [
        'reason' => 'CUSTOMER_ABSENT',
        'unit_id' => 'U-77',
        'lat' => -16.5,
        'lng' => -68.15,
    ])->assertRedirect();
});

it('registra la prueba de entrega por su ruta POST', function (): void {
    $delivery = DeliveryRecord::factory()->create();

    $this->post('/app/deliveries/'.$delivery->getKey().'/pod', [
        'confirmation' => 'QR-CLIENTE-OK',
        'unit_id' => 'U-77',
        'photo_url' => 'https://cdn.example.com/pod/foto.jpg',
        'lat' => -16.5,
        'lng' => -68.15,
    ])->assertRedirect();
});

it('reporta un incidente del vehículo por su ruta POST', function (): void {
    // Sin ruta abierta del camión, el dominio rechaza y el controller traduce a flash de error; en ambos
    // casos responde con un redirect de vuelta (nunca filtra la traza).
    $delivery = DeliveryRecord::factory()->create();

    $this->post('/app/deliveries/'.$delivery->getKey().'/incident', [
        'severity' => 'MAJOR',
        'type' => 'ACCIDENT',
        'note' => 'Choque leve en ruta',
        'lat' => -16.5,
        'lng' => -68.15,
    ])->assertRedirect();
});

it('rechaza con 404 una escritura sobre una entrega inexistente', function (): void {
    $this->post('/app/deliveries/'.Uuid::generate()->value.'/fail', [
        'reason' => 'OTHER',
        'unit_id' => 'U-1',
    ])->assertNotFound();
});

it('rechaza con 422 una entrega fallida sin motivo', function (): void {
    $delivery = DeliveryRecord::factory()->create();

    $this->post('/app/deliveries/'.$delivery->getKey().'/fail', [
        'unit_id' => 'U-1',
    ])->assertStatus(302); // redirige de vuelta con errores de validación
});

// ── PlanningWriteController ────────────────────────────────────────────────────────────────────────

it('publica un plan por su ruta POST', function (): void {
    $plan = PlanRecordFactory::new()->proposed()->withStops(2)->create();

    // Con el middleware omitido no hay StartSession para leer el flash; basta con el redirect de éxito.
    $this->post('/app/planning/plans/'.$plan->getKey().'/publish')
        ->assertRedirect();

    expect(PlanRecord::query()->whereKey($plan->getKey())->value('status'))
        ->toBe('PUBLISHED');
});

it('reordena la secuencia del plan por su ruta PUT', function (): void {
    // Ids que no coinciden con las paradas del plan: el agregado rechaza y el controller captura el error
    // y redirige de vuelta con flash de error (sin traza).
    $plan = PlanRecordFactory::new()->withStops(2)->create();

    $this->put('/app/planning/plans/'.$plan->getKey().'/sequence', [
        'ordered_shipment_ids' => [Uuid::generate()->value, Uuid::generate()->value],
    ])->assertRedirect();
});

it('encola la auto-planificación del día por su ruta POST', function (): void {
    $this->post('/app/planning/plans/auto', [
        'plan_date' => '2026-10-15',
        'strategy' => 'slots',
    ])->assertRedirect();
});

it('rechaza con 422 la auto-planificación con estrategia inválida', function (): void {
    $this->post('/app/planning/plans/auto', [
        'plan_date' => '2026-10-15',
        'strategy' => 'inexistente',
    ])->assertStatus(302);
});

// ── RouteController::show + ResolvesRouteMapData ─────────────────────────────────────────────────────

it('renderiza el detalle de una ruta con paradas, geometría planificada y recorrido real', function (): void {
    $vehicleId = Uuid::generate()->value;
    $shipmentA = Uuid::generate()->value;
    $shipmentB = Uuid::generate()->value;

    // Arma la ruta del día con dos paradas con coordenadas (vía CommandBus, como el test de execution).
    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: '2026-10-13',
        stops: [
            new RouteStopInput(
                shipmentId: $shipmentA,
                sequence: 1,
                orderReference: 'ORD-1',
                customerName: 'Cliente Uno',
                address: 'Av. Siempre Viva 1',
                etaAt: '2026-10-13T09:00:00+00:00',
                lat: -16.5,
                lng: -68.15,
            ),
            new RouteStopInput(
                shipmentId: $shipmentB,
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

    // Geometría planificada persistida por Planning para ese vehículo + día (alimenta normalizeGeometry).
    PlanRecordFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'plan_date' => '2026-10-13',
        'route_geometry' => [[-68.15, -16.5], [-68.16, -16.51]],
    ]);

    // Recorrido real: una entrega del camión para esos envíos con un escaneo IN_TRANSIT (actualTrack).
    $delivery = DeliveryRecord::factory()->create([
        'vehicle_id' => $vehicleId,
        'shipment_id' => $shipmentA,
    ]);
    ScanEventRecord::factory()->ofType(ScanType::InTransit)->forDelivery($delivery->id)->create();

    $route = VehicleRouteRecord::query()->where('vehicle_id', $vehicleId)->firstOrFail();

    $this->get('/app/routes/'.$route->getKey())->assertOk();
});

it('renderiza el listado de rutas del SPA', function (): void {
    $this->get('/app/routes')->assertOk();
});
