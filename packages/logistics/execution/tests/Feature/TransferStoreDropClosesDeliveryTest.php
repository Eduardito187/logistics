<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Command\CloseTransferWithShortage\CloseTransferWithShortage;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Infrastructure\Outbox\StoreDropOnReceptionReconciled;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// TRASPASO interno: no hay cliente que firme una prueba de entrega, así que la entrega en tienda en SU nodo
// destino ES la entrega. Sin faltantes queda entregado (mismo hecho de entrega que el resto del sistema) y la
// parada del chofer se cierra; con faltantes queda en curso hasta que la tienda concilie la recepción (completa:
// se cierra sin faltantes; con diferencias: se cierra CON faltantes) o la oficina lo cierre a mano con un motivo.
// El retiro en tienda de un cliente NO cambia: lo cierra el mostrador con la prueba de entrega.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Envío con destino en una tienda, con $units productos, en la ruta de un camión y con la entrega abierta.
 * `$transfer` decide si es un traspaso interno o un pedido de cliente con retiro en tienda.
 * Devuelve [shipmentId, storeId, unitIds].
 *
 * @return array{0: string, 1: string, 2: list<string>}
 */
function tsdShipmentOnTruck(bool $transfer, int $units = 2): array
{
    $storeId = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $storeId,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => 'Tienda de prueba',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
    ]);

    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'TSD-'.Str::random(6),
        'destination_node_id' => $storeId,
        'source' => $transfer ? 'TRANSFER' : 'MAGENTO',
        'status' => 'IN_EXECUTION',
    ]);
    $shipmentId = (string) $shipment->id;

    $lineId = (string) Str::uuid7();
    DB::table('shipment_lines')->insert([
        'id' => $lineId,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-TV-55',
        'product_name' => 'Televisor LED 55',
        'quantity' => $units,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unitIds = [];
    for ($i = 1; $i <= $units; $i++) {
        $unitId = (string) Str::uuid7();
        DB::table('shipment_units')->insert([
            'id' => $unitId,
            'shipment_id' => $shipmentId,
            'line_id' => $lineId,
            'unit_index' => $i,
            'unit_total' => $units,
            'piece_index' => 1,
            'piece_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitIds[] = $unitId;
    }

    $vehicleId = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: '2026-11-14',
        stops: [
            new RouteStopInput(
                shipmentId: $shipmentId,
                sequence: 1,
                orderReference: 'TSD-REF',
                customerName: 'Tienda de prueba',
                address: 'Av. Siempre Viva 1',
                etaAt: '2026-11-14T15:00:00+00:00',
                lat: -17.78,
                lng: -63.18,
            ),
        ],
    ));
    DeliveryRecord::factory()->create(['shipment_id' => $shipmentId, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);

    return [$shipmentId, $storeId, $unitIds];
}

/** Cantidad de hechos de un tipo grabados en el outbox para el envío. */
function tsdEvents(string $shipmentId, string $eventName): int
{
    return DB::table('outbox_messages')->where('event_name', $eventName)->where('payload->shipment_id', $shipmentId)->count();
}

/**
 * Payload del único hecho de entrega grabado para el envío (vacío si no hay ninguno).
 *
 * @return array<string, mixed>
 */
function tsdDeliveredPayload(string $shipmentId): array
{
    $row = DB::table('outbox_messages')
        ->where('event_name', 'execution.shipment_delivered')
        ->where('payload->shipment_id', $shipmentId)
        ->first();
    $payload = json_decode((string) ($row->payload ?? '{}'), true);

    return is_array($payload) ? $payload : [];
}

/** ¿Están las columnas del cierre con faltantes? (migración 2026_11_15_000100). */
function tsdHasShortageColumns(): bool
{
    return Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')
        && Schema::hasColumn('execution_deliveries', 'closed_with_shortage');
}

/** Usuario autenticado por Sanctum con el rol dado, con el middleware de roles activo. */
function tsdActingAs(Role $role): User
{
    test()->withMiddleware();
    test()->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);

    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user, 'sanctum');

    return $user;
}

/**
 * Mensaje de outbox de una recepción conciliada en el nodo dado.
 *
 * @param list<string> $missingUnitIds faltantes que informó la tienda al conciliar
 */
function tsdReconciled(string $shipmentId, string $nodeId, bool $complete, int $expected, array $missingUnitIds = []): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.node_reception',
        (string) Str::uuid7(),
        'execution.node_reception_reconciled',
        [
            'shipment_id' => $shipmentId,
            'destination_node_id' => $nodeId,
            'complete' => $complete,
            'expected_count' => $expected,
            'missing_unit_ids' => $missingUnitIds,
        ],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

it('un traspaso sin faltantes queda entregado al dejarlo en su tienda destino', function (): void {
    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true);

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $delivered = DB::table('outbox_messages')
        ->where('event_name', 'execution.shipment_delivered')
        ->where('payload->shipment_id', $shipmentId)
        ->get();
    $payload = json_decode((string) ($delivered[0]->payload ?? '{}'), true);

    expect($delivery->status)->toBe('DELIVERED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        // Sin prueba de entrega: no se exige foto ni firma.
        ->and($delivery->pod_confirmation)->toBeNull()
        ->and($delivered)->toHaveCount(1)
        ->and($payload['shipment_id'] ?? null)->toBe($shipmentId)
        ->and($payload['confirmation'] ?? null)->toBe('STORE_DROP')
        ->and(strtotime((string) ($payload['occurred_at'] ?? '')))->toBe(strtotime('2026-11-14T11:00:00-04:00'))
        ->and($payload['unit_ids'] ?? null)->toEqualCanonicalizing($unitIds)
        // El hecho de la entrega en tienda se sigue grabando igual (seguimiento, avisos).
        ->and(tsdEvents($shipmentId, 'execution.shipment_dropped_at_store'))->toBe(1)
        // La parada del chofer queda cerrada, igual que con la prueba de entrega.
        ->and(DB::table('execution_driver_route_stops')->where('shipment_id', $shipmentId)->value('status'))->toBe('COMPLETED');
});

it('un traspaso con foto la conserva y queda entregado igual', function (): void {
    [$shipmentId, $storeId] = tsdShipmentOnTruck(transfer: true);

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00', 'pod/'.$shipmentId.'/tienda.jpg'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('DELIVERED')
        ->and($delivery->getAttribute('store_drop_photo_path'))->toBe('pod/'.$shipmentId.'/tienda.jpg');
});

it('un traspaso con faltantes sigue en curso', function (): void {
    if (!Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')) {
        test()->markTestSkipped('execution_deliveries.store_drop_missing_units todavía no existe (migración pendiente).');
    }

    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);

    app(CommandBus::class)->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => $unitIds[2], 'reason' => 'damaged']],
    ));
    // Repetirla sin la lista no borra los faltantes ya declarados ni lo cierra.
    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:05:00-04:00'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('LOADED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(0)
        ->and(tsdEvents($shipmentId, 'execution.shipment_dropped_at_store'))->toBe(1);
});

it('repetir la entrega en tienda de un traspaso no vuelve a entregarlo ni falla', function (): void {
    [$shipmentId, $storeId] = tsdShipmentOnTruck(transfer: true);

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));
    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:05:00-04:00', 'pod/foto-tardia.jpg'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('DELIVERED')
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(1)
        ->and(tsdEvents($shipmentId, 'execution.shipment_dropped_at_store'))->toBe(1)
        // La foto que llegó después se liga igual.
        ->and($delivery->getAttribute('store_drop_photo_path'))->toBe('pod/foto-tardia.jpg');
});

it('un retiro en tienda de cliente no se cierra con la entrega en tienda', function (): void {
    [$shipmentId, $storeId] = tsdShipmentOnTruck(transfer: false);

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('LOADED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(0)
        ->and(tsdEvents($shipmentId, 'execution.shipment_dropped_at_store'))->toBe(1)
        ->and(DB::table('execution_driver_route_stops')->where('shipment_id', $shipmentId)->value('status'))->toBe('COMPLETED');
});

it('la recepción completa conciliada en su destino cierra el traspaso que quedó con faltantes', function (): void {
    if (!Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')) {
        test()->markTestSkipped('execution_deliveries.store_drop_missing_units todavía no existe (migración pendiente).');
    }

    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);
    $reactor = app(StoreDropOnReceptionReconciled::class);

    app(CommandBus::class)->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => $unitIds[2], 'reason' => 'not_arrived']],
    ));

    // La recepción completa lo cierra SIN faltantes, una sola vez aunque el outbox reentregue.
    $reactor->dispatch(tsdReconciled($shipmentId, $storeId, true, 3));
    $reactor->dispatch(tsdReconciled($shipmentId, $storeId, true, 3));

    $payload = tsdDeliveredPayload($shipmentId);

    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->status)->toBe('DELIVERED')
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(1)
        ->and(tsdEvents($shipmentId, 'execution.shipment_dropped_at_store'))->toBe(1)
        ->and($payload['with_shortage'] ?? null)->toBeFalse()
        ->and($payload['missing_unit_ids'] ?? null)->toBe([])
        ->and($payload['unit_ids'] ?? null)->toEqualCanonicalizing($unitIds);
});

it('la conciliación con diferencias cierra el traspaso con faltantes', function (): void {
    if (!tsdHasShortageColumns()) {
        test()->markTestSkipped('Faltan las columnas del cierre con faltantes en execution_deliveries (migración pendiente).');
    }

    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);
    $reactor = app(StoreDropOnReceptionReconciled::class);

    app(CommandBus::class)->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => $unitIds[2], 'reason' => 'not_arrived']],
    ));
    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->status)->toBe('LOADED');

    // La tienda concilia con diferencias sin listar faltantes: valen los que declaró el chofer. Una sola vez
    // aunque el outbox reentregue.
    $reactor->dispatch(tsdReconciled($shipmentId, $storeId, false, 3));
    $reactor->dispatch(tsdReconciled($shipmentId, $storeId, false, 3));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $payload = tsdDeliveredPayload($shipmentId);

    expect($delivery->status)->toBe('DELIVERED')
        ->and((bool) $delivery->getAttribute('closed_with_shortage'))->toBeTrue()
        ->and($delivery->getAttribute('shortage_closed_at'))->not->toBeNull()
        // La conciliación no es un cierre manual: sin motivo ni usuario.
        ->and($delivery->getAttribute('shortage_close_reason'))->toBeNull()
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(1)
        ->and($payload['confirmation'] ?? null)->toBe('STORE_DROP')
        ->and($payload['with_shortage'] ?? null)->toBeTrue()
        ->and($payload['missing_unit_ids'] ?? null)->toBe([$unitIds[2]])
        // Los faltantes no cuentan como entregados.
        ->and($payload['unit_ids'] ?? null)->toEqualCanonicalizing([$unitIds[0], $unitIds[1]])
        ->and($payload['units_delivered'] ?? null)->toBe(2);
});

it('la conciliación con diferencias usa los faltantes que informó la tienda', function (): void {
    if (!tsdHasShortageColumns()) {
        test()->markTestSkipped('Faltan las columnas del cierre con faltantes en execution_deliveries (migración pendiente).');
    }

    // El chofer nunca registró su entrega en tienda: la registra el sistema al conciliar.
    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);

    app(StoreDropOnReceptionReconciled::class)->dispatch(tsdReconciled($shipmentId, $storeId, false, 3, [$unitIds[0]]));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $payload = tsdDeliveredPayload($shipmentId);

    expect($delivery->status)->toBe('DELIVERED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and((bool) $delivery->getAttribute('closed_with_shortage'))->toBeTrue()
        ->and($payload['with_shortage'] ?? null)->toBeTrue()
        ->and($payload['missing_unit_ids'] ?? null)->toBe([$unitIds[0]])
        ->and($payload['unit_ids'] ?? null)->toEqualCanonicalizing([$unitIds[1], $unitIds[2]]);
});

it('la oficina cierra a mano un traspaso con faltantes que la tienda nunca concilió', function (): void {
    if (!tsdHasShortageColumns()) {
        test()->markTestSkipped('Faltan las columnas del cierre con faltantes en execution_deliveries (migración pendiente).');
    }

    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);
    $bus = app(CommandBus::class);

    $bus->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => $unitIds[2], 'reason' => 'damaged']],
    ));

    $bus->dispatch(new CloseTransferWithShortage($shipmentId, 'La tienda no concilió la recepción', '42', '2026-11-15T09:00:00-04:00'));
    // Idempotente: repetirlo no vuelve a entregarlo ni pisa el motivo.
    $bus->dispatch(new CloseTransferWithShortage($shipmentId, 'Otro motivo', '43', '2026-11-15T10:00:00-04:00'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $payload = tsdDeliveredPayload($shipmentId);

    expect($delivery->status)->toBe('DELIVERED')
        ->and((bool) $delivery->getAttribute('closed_with_shortage'))->toBeTrue()
        ->and($delivery->getAttribute('shortage_close_reason'))->toBe('La tienda no concilió la recepción')
        ->and($delivery->getAttribute('shortage_closed_by'))->toBe('42')
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(1)
        ->and($payload['confirmation'] ?? null)->toBe('STORE_DROP')
        ->and($payload['with_shortage'] ?? null)->toBeTrue()
        ->and($payload['missing_unit_ids'] ?? null)->toBe([$unitIds[2]])
        ->and($payload['unit_ids'] ?? null)->toEqualCanonicalizing([$unitIds[0], $unitIds[1]])
        ->and(strtotime((string) ($payload['occurred_at'] ?? '')))->toBe(strtotime('2026-11-15T09:00:00-04:00'));
});

it('el cierre de oficina solo aplica a traspasos con entrega en tienda y faltantes', function (): void {
    $bus = app(CommandBus::class);

    // Un retiro en tienda de cliente no es un traspaso.
    [$pickupId, $pickupStore] = tsdShipmentOnTruck(transfer: false);
    $bus->dispatch(new RecordStoreDrop($pickupId, $pickupStore, '2026-11-14T11:00:00-04:00'));
    expect(fn () => $bus->dispatch(new CloseTransferWithShortage($pickupId, 'Motivo de prueba', '42', '2026-11-15T09:00:00-04:00')))
        ->toThrow(ExecutionException::class, 'no es un traspaso interno');

    // Un traspaso que el chofer todavía no dejó en la tienda no se puede cerrar así.
    [$transferId] = tsdShipmentOnTruck(transfer: true);
    expect(fn () => $bus->dispatch(new CloseTransferWithShortage($transferId, 'Motivo de prueba', '42', '2026-11-15T09:00:00-04:00')))
        ->toThrow(ExecutionException::class, 'entrega en tienda registrada');

    expect(DeliveryRecord::query()->where('shipment_id', $pickupId)->firstOrFail()->status)->toBe('LOADED')
        ->and(DeliveryRecord::query()->where('shipment_id', $transferId)->firstOrFail()->status)->toBe('LOADED')
        ->and(tsdEvents($pickupId, 'execution.shipment_delivered'))->toBe(0)
        ->and(tsdEvents($transferId, 'execution.shipment_delivered'))->toBe(0);
});

it('POST close-transfer: el supervisor cierra el traspaso con faltantes y repetirlo no cambia nada', function (): void {
    if (!tsdHasShortageColumns()) {
        test()->markTestSkipped('Faltan las columnas del cierre con faltantes en execution_deliveries (migración pendiente).');
    }

    [$shipmentId, $storeId, $unitIds] = tsdShipmentOnTruck(transfer: true, units: 3);
    app(CommandBus::class)->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => $unitIds[2], 'reason' => 'not_arrived']],
    ));

    $supervisor = tsdActingAs(Role::Supervisor);
    $url = "/api/v1/app/deliveries/{$shipmentId}/close-transfer";

    test()->postJson($url, ['reason' => 'La tienda no concilió la recepción'])
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.with_shortage', true)
        ->assertJsonPath('data.shortage_close_reason', 'La tienda no concilió la recepción');

    // Idempotente: 200 con el mismo estado, sin un segundo hecho de entrega.
    test()->postJson($url, ['reason' => 'Segundo intento'])
        ->assertOk()
        ->assertJsonPath('data.status', 'DELIVERED')
        ->assertJsonPath('data.shortage_close_reason', 'La tienda no concilió la recepción');

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(1)
        ->and($delivery->getAttribute('shortage_closed_by'))->toBe((string) $supervisor->getAuthIdentifier());
});

it('POST close-transfer: 403 para un rol que no es de oficina', function (): void {
    [$shipmentId] = tsdShipmentOnTruck(transfer: true);
    tsdActingAs(Role::Chofer);

    test()->postJson("/api/v1/app/deliveries/{$shipmentId}/close-transfer", ['reason' => 'No debería poder'])
        ->assertStatus(403);

    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->status)->toBe('LOADED');
});

it('POST close-transfer: 422 si el envío no es un traspaso o falta el motivo', function (): void {
    [$pickupId] = tsdShipmentOnTruck(transfer: false);
    [$transferId] = tsdShipmentOnTruck(transfer: true);
    tsdActingAs(Role::Admin);

    test()->postJson("/api/v1/app/deliveries/{$pickupId}/close-transfer", ['reason' => 'No es un traspaso'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_a_transfer');

    test()->postJson("/api/v1/app/deliveries/{$transferId}/close-transfer", [])
        ->assertStatus(422);

    // Traspaso sin entrega en tienda registrada: regla de negocio, con el motivo en `detail`.
    test()->postJson("/api/v1/app/deliveries/{$transferId}/close-transfer", ['reason' => 'Todavía no llegó'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');

    expect(tsdEvents($pickupId, 'execution.shipment_delivered'))->toBe(0)
        ->and(tsdEvents($transferId, 'execution.shipment_delivered'))->toBe(0);
});

it('la recepción completa de un retiro en tienda no lo deja entregado', function (): void {
    [$shipmentId, $storeId] = tsdShipmentOnTruck(transfer: false);

    app(StoreDropOnReceptionReconciled::class)->dispatch(tsdReconciled($shipmentId, $storeId, true, 2));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('LOADED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and(tsdEvents($shipmentId, 'execution.shipment_delivered'))->toBe(0);
});
