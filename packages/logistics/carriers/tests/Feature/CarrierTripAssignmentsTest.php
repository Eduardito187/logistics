<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Carriers\Application\Command\ScheduleCarrierTrip\ScheduleCarrierTrip;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Carga asignada al transportista (auditoría app #4): GET /app/carriers/me/assignments y
 * POST /app/carriers/trips/{id}/start|close.
 *
 * Alcance server-side por EnsureCarrierScope: el transportista ve/opera SOLO los viajes de su
 * transportista (users.carrier_id); ajeno → 403; oficina ve todos. Iniciar/cerrar son idempotentes y una
 * transición inválida es 422. Contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function carrierTripFeatureActingAs(Role $role, ?string $carrierId = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['carrier_id' => $carrierId])->save();
    test()->actingAs($user, 'sanctum');

    return $user;
}

function carrierTripFeatureNode(string $code, string $name): string
{
    $id = Str::uuid7()->toString();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => $code,
        'name' => $name,
        'type' => 'cd',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Envío con una línea de N productos físicos (unidades). Devuelve el id del envío.
 */
function carrierTripFeatureShipment(string $externalId, string $customer, ?string $destinationNodeId, string $sku, string $productName, int $units): string
{
    $shipmentId = Str::uuid7()->toString();
    $lineId = Str::uuid7()->toString();

    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'test',
        'external_id' => $externalId,
        'order_reference' => 'ORD-'.$externalId,
        'customer_name' => $customer,
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'accepted',
        'destination_node_id' => $destinationNodeId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('shipment_lines')->insert([
        'id' => $lineId,
        'shipment_id' => $shipmentId,
        'sku' => $sku,
        'product_name' => $productName,
        'quantity' => $units,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    for ($i = 1; $i <= $units; ++$i) {
        DB::table('shipment_units')->insert([
            'id' => Str::uuid7()->toString(),
            'shipment_id' => $shipmentId,
            'line_id' => $lineId,
            'unit_index' => $i,
            'unit_total' => $units,
            'piece_index' => 1,
            'piece_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $shipmentId;
}

/**
 * Programa un viaje por el CommandBus (camino real: validaciones + outbox). Devuelve su id.
 *
 * @param list<string> $shipmentIds
 */
function carrierTripFeatureSchedule(string $carrierId, string $from, string $to, array $shipmentIds): string
{
    $tripId = Str::uuid7()->toString();
    app(CommandBus::class)->dispatch(new ScheduleCarrierTrip(
        carrierId: $carrierId,
        corridorFrom: $from,
        corridorTo: $to,
        scheduledAt: '2026-11-13T08:00:00-04:00',
        reference: 'MAN-'.substr($tripId, -4),
        shipmentIds: $shipmentIds,
        tripId: $tripId,
    ));

    return $tripId;
}

it('el transportista ve SOLO los viajes de su transportista, con la forma del contrato', function (): void {
    $mine = CarrierFactory::new()->create(['name' => 'Transportes Propios']);
    $other = CarrierFactory::new()->create(['name' => 'Transportes Ajenos']);
    $from = carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz');
    $to = carrierTripFeatureNode('CD-LPZ', 'CD La Paz');
    $store = carrierTripFeatureNode('T-LPZ-01', 'Tienda El Prado');

    $shipment = carrierTripFeatureShipment('EXT-1', 'Juan Pérez', $store, 'SKU-RD-1', 'Heladera Samsung', 2);
    $foreignShipment = carrierTripFeatureShipment('EXT-2', 'Ana Gómez', null, 'SKU-TV-9', 'Televisor 55', 1);

    $tripId = carrierTripFeatureSchedule($mine->id, $from, $to, [$shipment]);
    carrierTripFeatureSchedule($other->id, $from, $to, [$foreignShipment]);

    carrierTripFeatureActingAs(Role::Transportista, $mine->id);

    // Aunque pida los del otro transportista, recibe solo los suyos.
    $response = $this->getJson("/api/v1/app/carriers/me/assignments?carrier_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.trip_id', $tripId)
        ->assertJsonPath('data.0.carrier_id', $mine->id)
        ->assertJsonPath('data.0.carrier_name', 'Transportes Propios')
        ->assertJsonPath('data.0.corridor_from', 'CD Santa Cruz')
        ->assertJsonPath('data.0.corridor_to', 'CD La Paz')
        ->assertJsonPath('data.0.status', 'scheduled')
        ->assertJsonPath('data.0.expected_units_count', 2)
        ->assertJsonPath('data.0.shipments.0.shipment_id', $shipment)
        ->assertJsonPath('data.0.shipments.0.external_id', 'EXT-1')
        ->assertJsonPath('data.0.shipments.0.order_reference', 'ORD-EXT-1')
        ->assertJsonPath('data.0.shipments.0.customer_name', 'Juan Pérez')
        ->assertJsonPath('data.0.shipments.0.destination_node', 'Tienda El Prado')
        ->assertJsonPath('data.0.shipments.0.units.0.sku', 'SKU-RD-1')
        ->assertJsonPath('data.0.shipments.0.units.0.product_name', 'Heladera Samsung')
        ->assertJsonPath('data.0.shipments.0.units.0.product_label', 'Heladera Samsung · SKU-RD-1')
        ->assertJsonStructure([
            'data' => [[
                'trip_id', 'carrier_id', 'carrier_name', 'reference', 'corridor_from', 'corridor_from_node_id',
                'corridor_to', 'corridor_to_node_id', 'scheduled_at', 'status', 'started_at', 'closed_at',
                'shipments_count', 'expected_units_count',
                'shipments' => [[
                    'shipment_id', 'external_id', 'order_reference', 'customer_name', 'destination_node',
                    'destination_node_id', 'units_count',
                    'units' => [['unit_id', 'sku', 'product_name', 'product_label', 'piece_index', 'piece_total']],
                ]],
            ]],
            'meta',
        ]);

    expect($response->json('data.0.shipments.0.units'))->toHaveCount(2);
});

it('oficina ve los viajes de todos los transportistas', function (): void {
    $a = CarrierFactory::new()->create();
    $b = CarrierFactory::new()->create();
    $from = carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz');
    $to = carrierTripFeatureNode('CD-CBB', 'CD Cochabamba');

    carrierTripFeatureSchedule($a->id, $from, $to, [carrierTripFeatureShipment('EXT-A', 'Cliente A', null, 'SKU-A', 'Lavadora', 1)]);
    carrierTripFeatureSchedule($b->id, $from, $to, [carrierTripFeatureShipment('EXT-B', 'Cliente B', null, 'SKU-B', 'Cocina', 1)]);

    carrierTripFeatureActingAs(Role::Admin);

    $this->getJson('/api/v1/app/carriers/me/assignments')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/app/carriers/me/assignments?carrier_id={$b->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.carrier_id', $b->id);
});

it('iniciar y cerrar son idempotentes y devuelven started_at / closed_at / status', function (): void {
    $carrier = CarrierFactory::new()->create();
    $from = carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz');
    $to = carrierTripFeatureNode('CD-LPZ', 'CD La Paz');
    $tripId = carrierTripFeatureSchedule($carrier->id, $from, $to, [
        carrierTripFeatureShipment('EXT-3', 'Cliente', null, 'SKU-3', 'Microondas', 1),
    ]);

    carrierTripFeatureActingAs(Role::Transportista, $carrier->id);

    $first = $this->postJson("/api/v1/app/carriers/trips/{$tripId}/start")
        ->assertOk()
        ->assertJsonPath('data.trip_id', $tripId)
        ->assertJsonPath('data.status', 'in_progress');
    $startedAt = $first->json('data.started_at');
    expect($startedAt)->toBeString();

    // Reintento: mismo started_at, sin evento duplicado.
    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/start")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.started_at', $startedAt);

    $closed = $this->postJson("/api/v1/app/carriers/trips/{$tripId}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
    $closedAt = $closed->json('data.closed_at');
    expect($closedAt)->toBeString();

    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/close")
        ->assertOk()
        ->assertJsonPath('data.closed_at', $closedAt);

    expect(DB::table('outbox_messages')->where('aggregate_id', $tripId)->where('event_name', 'carriers.carrier_trip_started')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $tripId)->where('event_name', 'carriers.carrier_trip_closed')->count())->toBe(1);

    // Ya cerrado, iniciar es una transición inválida → 422.
    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/start")
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');
});

it('cerrar un viaje programado (sin iniciar) es 422', function (): void {
    $carrier = CarrierFactory::new()->create();
    $tripId = carrierTripFeatureSchedule(
        $carrier->id,
        carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz'),
        carrierTripFeatureNode('CD-TJA', 'CD Tarija'),
        [carrierTripFeatureShipment('EXT-4', 'Cliente', null, 'SKU-4', 'Licuadora', 1)],
    );

    carrierTripFeatureActingAs(Role::Transportista, $carrier->id);

    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/close")
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');

    expect(DB::table('carrier_trips')->where('id', $tripId)->value('status'))->toBe('scheduled');
});

it('otro transportista no puede iniciar ni cerrar un viaje ajeno (403)', function (): void {
    $owner = CarrierFactory::new()->create();
    $intruder = CarrierFactory::new()->create();
    $tripId = carrierTripFeatureSchedule(
        $owner->id,
        carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz'),
        carrierTripFeatureNode('CD-LPZ', 'CD La Paz'),
        [carrierTripFeatureShipment('EXT-5', 'Cliente', null, 'SKU-5', 'Freezer', 1)],
    );

    carrierTripFeatureActingAs(Role::Transportista, $intruder->id);

    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/start")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/close")
        ->assertForbidden();
    $this->getJson('/api/v1/app/carriers/me/assignments')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(DB::table('carrier_trips')->where('id', $tripId)->value('status'))->toBe('scheduled');
});

it('oficina puede iniciar el viaje de cualquier transportista; viaje inexistente → 404', function (): void {
    $carrier = CarrierFactory::new()->create();
    $tripId = carrierTripFeatureSchedule(
        $carrier->id,
        carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz'),
        carrierTripFeatureNode('CD-LPZ', 'CD La Paz'),
        [carrierTripFeatureShipment('EXT-6', 'Cliente', null, 'SKU-6', 'Horno', 1)],
    );

    carrierTripFeatureActingAs(Role::Supervisor);

    $this->postJson("/api/v1/app/carriers/trips/{$tripId}/start")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    $this->postJson('/api/v1/app/carriers/trips/'.Str::uuid7()->toString().'/start')
        ->assertNotFound();
});

it('un transportista sin vínculo y otros roles reciben 403', function (): void {
    carrierTripFeatureActingAs(Role::Transportista);
    $this->getJson('/api/v1/app/carriers/me/assignments')->assertForbidden();

    carrierTripFeatureActingAs(Role::Chofer);
    $this->getJson('/api/v1/app/carriers/me/assignments')->assertForbidden();
});

it('un envío no puede estar en dos viajes vigentes a la vez', function (): void {
    $carrier = CarrierFactory::new()->create();
    $from = carrierTripFeatureNode('CD-SCZ', 'CD Santa Cruz');
    $to = carrierTripFeatureNode('CD-LPZ', 'CD La Paz');
    $shipment = carrierTripFeatureShipment('EXT-7', 'Cliente', null, 'SKU-7', 'Aire acondicionado', 1);

    carrierTripFeatureSchedule($carrier->id, $from, $to, [$shipment]);

    expect(fn () => carrierTripFeatureSchedule($carrier->id, $from, $to, [$shipment]))
        ->toThrow(Logistics\Carriers\Domain\Exception\CarriersException::class);
});
