<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\DispatchTimeline as TimelineData;
use App\Support\Cockpit\ShipmentPipeline as PipelineData;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Presentation\Filament\Pages\LastMileBoard;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Presentation\Filament\PlannedStopResource;
use Logistics\Planning\Presentation\Filament\PlanResource;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

/**
 * Alcance por CIUDAD del planificador en el admin web: con datos de dos ciudades, el planificador de una ve
 * solo lo suyo en cada recurso y tablero que puede abrir (pedidos, líneas, productos, planes, paradas,
 * entregas, rutas, seguimiento, pipeline de envíos, línea de tiempo y tablero de última milla).
 *
 * También: el administrador y el supervisor ven lo mismo que antes (su consulta no cambia), el planificador
 * sin ciudad ve las dos ciudades y, con `scope.strict_without_city`, no ve nada. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    // Los Resources/Pages resuelven el panel actual; en pruebas se fija a mano.
    Filament::setCurrentPanel('admin');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Usuario del panel con ese rol y esa ciudad (null = sin ciudad), ya autenticado. */
function pcsActingAs(Role $role, ?string $city = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    if ($city !== null) {
        $user->forceFill(['city' => $city])->save();
    }
    test()->actingAs($user);

    return $user;
}

/** Centro de distribución de ese departamento; devuelve su id. */
function pcsNode(string $department): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'PCS-'.Str::upper(Str::random(8)),
        'name' => 'CD '.$department,
        'type' => 'distribution_center',
        'department' => $department,
        'lat' => -17.0,
        'lng' => -65.0,
        'status' => 'active',
    ]);

    return $id;
}

/** Pedido que sale de `$originNode` (departamento `$origin`) con destino en el departamento `$destination`. */
function pcsShipment(string $originNode, string $origin, string $destination): ShipmentRecord
{
    $order = 'PCS-'.strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -10));

    return ShipmentRecord::factory()->create([
        'external_id' => $order,
        'order_reference' => $order,
        'origin_node_id' => $originNode,
        'origin_department' => $origin,
        'destination_department' => $destination,
        'status' => 'PLANNED',
    ]);
}

/**
 * La operación completa de UNA ciudad: nodo, camión con base en él, un pedido local con su línea, su
 * producto, su parada en un plan, su entrega, su parada en una ruta del día y su seguimiento.
 *
 * @return array{node: string, vehicle: string, shipment: ShipmentRecord, line: string, unit: string, plan: string, stop: string, delivery: string, route: string, tracking: string}
 */
function pcsCity(string $department): array
{
    $node = pcsNode($department);
    $vehicle = VehicleFactory::new()->homeBase($node)->create();
    $shipment = pcsShipment($node, $department, $department);

    $line = ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->id]);
    $unit = PhysicalUnitRecord::factory()->create(['shipment_id' => $shipment->id, 'line_id' => $line->id]);

    $plan = PlanRecord::factory()->create(['vehicle_id' => $vehicle->id]);
    $stop = PlannedStopRecord::factory()->create(['plan_id' => $plan->id, 'shipment_id' => $shipment->id]);

    $delivery = DeliveryRecord::factory()->create(['shipment_id' => $shipment->id, 'vehicle_id' => $vehicle->id]);

    $route = Uuid::generate()->value;
    DB::table('execution_driver_routes')->insert([
        'id' => $route,
        'vehicle_id' => $vehicle->id,
        'route_date' => now()->toDateString(),
        'status' => RouteStatus::Planned->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value,
        'route_id' => $route,
        'shipment_id' => $shipment->id,
        'sequence' => 1,
        'order_reference' => $shipment->external_id,
        'status' => RouteStopStatus::Pending->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tracking = TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'order_reference' => $shipment->external_id,
    ]);

    return [
        'node' => $node,
        'vehicle' => (string) $vehicle->id,
        'shipment' => $shipment,
        'line' => (string) $line->id,
        'unit' => (string) $unit->id,
        'plan' => (string) $plan->id,
        'stop' => (string) $stop->id,
        'delivery' => (string) $delivery->id,
        'route' => $route,
        'tracking' => (string) $tracking->id,
    ];
}

/**
 * Ids que devuelve la consulta de un recurso, ordenados (para comparar conjuntos).
 *
 * @param  Builder<covariant Model> $query
 * @return list<string>
 */
function pcsIds(Builder $query): array
{
    $ids = array_map(strval(...), $query->pluck($query->getModel()->getQualifiedKeyName())->all());
    sort($ids);

    return array_values($ids);
}

/**
 * @param  list<string> $ids
 * @return list<string>
 */
function pcsSorted(array $ids): array
{
    sort($ids);

    return array_values($ids);
}

it('el planificador de una ciudad ve solo lo suyo en cada recurso', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    pcsActingAs(Role::Planificador, 'la_paz');

    expect(pcsIds(ShipmentResource::getEloquentQuery()))->toBe([(string) $lpz['shipment']->id])
        ->and(pcsIds(ShipmentLineResource::getEloquentQuery()))->toBe([$lpz['line']])
        ->and(pcsIds(PhysicalUnitResource::getEloquentQuery()))->toBe([$lpz['unit']])
        ->and(pcsIds(PlanResource::getEloquentQuery()))->toBe([$lpz['plan']])
        ->and(pcsIds(PlannedStopResource::getEloquentQuery()))->toBe([$lpz['stop']])
        ->and(pcsIds(DeliveryResource::getEloquentQuery()))->toBe([$lpz['delivery']])
        ->and(pcsIds(VehicleRouteResource::getEloquentQuery()))->toBe([$lpz['route']])
        ->and(pcsIds(ShipmentTrackingResource::getEloquentQuery()))->toBe([$lpz['tracking']]);

    // La ficha de un registro de otra ciudad no se resuelve (Filament responde 404); la propia sí.
    expect(ShipmentResource::resolveRecordRouteBinding((string) $scz['shipment']->id))->toBeNull()
        ->and(ShipmentResource::resolveRecordRouteBinding((string) $lpz['shipment']->id))->not->toBeNull()
        ->and(PlanResource::resolveRecordRouteBinding($scz['plan']))->toBeNull()
        ->and(DeliveryResource::resolveRecordRouteBinding($scz['delivery']))->toBeNull()
        ->and(VehicleRouteResource::resolveRecordRouteBinding($scz['route']))->toBeNull()
        ->and(ShipmentTrackingResource::resolveRecordRouteBinding($scz['tracking']))->toBeNull();
});

it('el mismo criterio vale para el preparador y se compara por código de departamento', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    // La ciudad escrita con mayúsculas y espacio se lleva al mismo código (`santa_cruz`).
    pcsActingAs(Role::Preparador, 'Santa Cruz');

    expect(pcsIds(ShipmentResource::getEloquentQuery()))->toBe([(string) $scz['shipment']->id])
        ->and(pcsIds(DeliveryResource::getEloquentQuery()))->toBe([$scz['delivery']])
        ->and(pcsIds(PlanResource::getEloquentQuery()))->not->toContain($lpz['plan']);
});

it('un pedido entra por origen o por destino, y arrastra el viaje que lo lleva', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');

    // Traspaso que SALE de Santa Cruz y LLEGA a La Paz, en el plan y en la ruta del camión de Santa Cruz.
    $transfer = pcsShipment($scz['node'], 'santa_cruz', 'la_paz');
    PlannedStopRecord::factory()->create(['plan_id' => $scz['plan'], 'shipment_id' => $transfer->id, 'sequence' => 2]);
    DB::table('execution_driver_route_stops')->insert([
        'id' => Uuid::generate()->value,
        'route_id' => $scz['route'],
        'shipment_id' => $transfer->id,
        'sequence' => 2,
        'status' => RouteStopStatus::Pending->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Pedido sin ningún departamento resuelto: no se esconde por un dato faltante (igual que la puerta app).
    $unresolved = ShipmentRecord::factory()->create(['status' => 'RECEIVED']);
    DB::table('shipments')->where('id', $unresolved->id)->update(['origin_department' => null, 'destination_department' => null]);

    pcsActingAs(Role::Planificador, 'la_paz');

    expect(pcsIds(ShipmentResource::getEloquentQuery()))
        ->toBe(pcsSorted([(string) $lpz['shipment']->id, (string) $transfer->id, (string) $unresolved->id]))
        // El plan y la ruta de Santa Cruz entran porque llevan un pedido de La Paz.
        ->and(pcsIds(PlanResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['plan'], $scz['plan']]))
        ->and(pcsIds(VehicleRouteResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['route'], $scz['route']]))
        // Pero de sus paradas solo ve la del pedido propio.
        ->and(pcsIds(PlannedStopResource::getEloquentQuery()))->not->toContain($scz['stop']);

    // Un plan todavía vacío es de la ciudad de la base de su camión.
    $emptyPlan = PlanRecord::factory()->onDate('2027-01-10')->create(['vehicle_id' => $scz['vehicle']]);
    expect(pcsIds(PlanResource::getEloquentQuery()))->not->toContain((string) $emptyPlan->id);

    pcsActingAs(Role::Planificador, 'santa_cruz');
    expect(pcsIds(PlanResource::getEloquentQuery()))->toContain((string) $emptyPlan->id);
});

it('el seguimiento sin envío ligado se decide por los envíos de su orden', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    $pending = TrackingRecord::factory()->create([
        'shipment_id' => null,
        'order_reference' => $scz['shipment']->order_reference,
    ]);

    pcsActingAs(Role::Planificador, 'la_paz');
    expect(pcsIds(ShipmentTrackingResource::getEloquentQuery()))->toBe([$lpz['tracking']]);

    pcsActingAs(Role::Planificador, 'santa_cruz');
    expect(pcsIds(ShipmentTrackingResource::getEloquentQuery()))->toBe(pcsSorted([$scz['tracking'], (string) $pending->id]));
});

it('administrador y supervisor ven las dos ciudades y su consulta no cambia', function (Role $role): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    // Aunque la cuenta tenga ciudad: la oficina nunca queda acotada.
    pcsActingAs($role, 'la_paz');

    expect(pcsIds(ShipmentResource::getEloquentQuery()))->toBe(pcsSorted([(string) $lpz['shipment']->id, (string) $scz['shipment']->id]))
        ->and(pcsIds(PlanResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['plan'], $scz['plan']]))
        ->and(pcsIds(DeliveryResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['delivery'], $scz['delivery']]))
        ->and(pcsIds(VehicleRouteResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['route'], $scz['route']]))
        ->and(pcsIds(ShipmentTrackingResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['tracking'], $scz['tracking']]));

    // Ni un predicado extra en su SQL.
    foreach ([
        ShipmentResource::getEloquentQuery(),
        ShipmentLineResource::getEloquentQuery(),
        PhysicalUnitResource::getEloquentQuery(),
        PlanResource::getEloquentQuery(),
        PlannedStopResource::getEloquentQuery(),
        DeliveryResource::getEloquentQuery(),
        VehicleRouteResource::getEloquentQuery(),
        ShipmentTrackingResource::getEloquentQuery(),
    ] as $query) {
        expect($query->toSql())->not->toContain('origin_department')
            ->and($query->toSql())->not->toContain('exists');
    }
})->with([
    'administrador' => [Role::Admin],
    'supervisor' => [Role::Supervisor],
]);

it('el planificador sin ciudad ve las dos ciudades; en modo estricto no ve nada', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    pcsActingAs(Role::Planificador);

    config(['logistics-shipments.scope.strict_without_city' => false]);
    expect(pcsIds(ShipmentResource::getEloquentQuery()))->toBe(pcsSorted([(string) $lpz['shipment']->id, (string) $scz['shipment']->id]))
        ->and(pcsIds(PlanResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['plan'], $scz['plan']]))
        ->and(pcsIds(DeliveryResource::getEloquentQuery()))->toBe(pcsSorted([$lpz['delivery'], $scz['delivery']]));

    config(['logistics-shipments.scope.strict_without_city' => true]);
    expect(ShipmentResource::getEloquentQuery()->count())->toBe(0)
        ->and(ShipmentLineResource::getEloquentQuery()->count())->toBe(0)
        ->and(PhysicalUnitResource::getEloquentQuery()->count())->toBe(0)
        ->and(PlanResource::getEloquentQuery()->count())->toBe(0)
        ->and(PlannedStopResource::getEloquentQuery()->count())->toBe(0)
        ->and(DeliveryResource::getEloquentQuery()->count())->toBe(0)
        ->and(VehicleRouteResource::getEloquentQuery()->count())->toBe(0)
        ->and(ShipmentTrackingResource::getEloquentQuery()->count())->toBe(0);

    // El modo estricto no toca a la oficina.
    pcsActingAs(Role::Supervisor);
    expect(ShipmentResource::getEloquentQuery()->count())->toBe(2);
});

it('el listado de pedidos muestra solo los de la ciudad del planificador', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    pcsActingAs(Role::Planificador, 'la_paz');

    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$lpz['shipment']])
        ->assertCanNotSeeTableRecords([$scz['shipment']]);
});

it('el pipeline de envíos y la línea de tiempo se acotan a la ciudad', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');

    $planned = static function (): array {
        foreach (app(PipelineData::class)->columns() as $column) {
            if ($column['status'] === 'PLANNED') {
                return ['count' => $column['count'], 'ids' => array_column($column['cards'], 'id')];
            }
        }

        return ['count' => 0, 'ids' => []];
    };
    $lanes = static fn (): array => pcsSorted(array_map(
        strval(...),
        array_column(app(TimelineData::class)->board()['lanes'], 'vehicle_id'),
    ));

    pcsActingAs(Role::Planificador, 'la_paz');
    expect($planned()['count'])->toBe(1)
        ->and($planned()['ids'])->toBe([(string) $lpz['shipment']->id])
        ->and($lanes())->toBe([$lpz['vehicle']]);

    pcsActingAs(Role::Admin);
    expect($planned()['count'])->toBe(2)
        ->and($lanes())->toBe(pcsSorted([$lpz['vehicle'], $scz['vehicle']]));
});

it('el tablero de última milla muestra solo las entregas de la ciudad', function (): void {
    $lpz = pcsCity('la_paz');
    $scz = pcsCity('santa_cruz');
    pcsActingAs(Role::Planificador, 'la_paz');

    Livewire::test(LastMileBoard::class)
        ->assertOk()
        ->assertSee($lpz['shipment']->external_id)
        ->assertDontSee($scz['shipment']->external_id);
});
