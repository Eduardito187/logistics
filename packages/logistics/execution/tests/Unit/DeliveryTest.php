<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DeliveryFailed;
use Logistics\Execution\Domain\Event\NodeReceptionRecorded;
use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Event\ShipmentDeparted;
use Logistics\Execution\Domain\Event\ShipmentLoaded;
use Logistics\Execution\Domain\Event\ShipmentPrepared;
use Logistics\Execution\Domain\Event\UnitLoaded;
use Logistics\Execution\Domain\Event\UnitPrepared;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;

/**
 * Arma una entrega nueva de ejemplo.
 */
function newDelivery(): Delivery
{
    return Delivery::start(DeliveryId::generate(), 'a1111111-1111-7111-8111-111111111111', 'b2222222-2222-7222-8222-222222222222');
}

function geo(): GeoPoint
{
    return GeoPoint::of(-16.5, -68.15);
}

function device(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

function at(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

it('starts a delivery in Pending with no scans', function (): void {
    $delivery = newDelivery();

    expect($delivery->status())->toBe(DeliveryStatus::Pending)
        ->and($delivery->scans())->toBe([])
        ->and($delivery->releaseEvents())->toBe([]);
});

it('runs the prepare -> load -> proof-of-delivery sequence recording an event per phase', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR-OK-123', 'https://cdn/foto.jpg', at('2026-10-13T12:00:00+00:00')),
        geo(),
        device(),
        at('2026-10-13T12:00:00+00:00'),
        'k-pod',
    );

    $events = $delivery->releaseEvents();

    // Cada escaneo emite su señal liviana por bulto (Unit*) y, en el primer bulto, además el hito del envío
    // (Shipment*): preparación → UnitPrepared + ShipmentPrepared; carga → UnitLoaded + ShipmentLoaded; POD →
    // ShipmentDelivered.
    expect($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($delivery->scans())->toHaveCount(3)
        ->and($events)->toHaveCount(5)
        ->and($events[0])->toBeInstanceOf(UnitPrepared::class)
        ->and($events[1])->toBeInstanceOf(ShipmentPrepared::class)
        ->and($events[2])->toBeInstanceOf(UnitLoaded::class)
        ->and($events[3])->toBeInstanceOf(ShipmentLoaded::class)
        ->and($events[4])->toBeInstanceOf(ShipmentDelivered::class);

    /** @var ShipmentDelivered $delivered */
    $delivered = $events[4];
    expect($delivered->eventName())->toBe('execution.shipment_delivered')
        ->and($delivered->payload()['confirmation'])->toBe('QR-OK-123')
        ->and($delivery->proofOfDelivery()?->confirmation)->toBe('QR-OK-123');
});

it('allows several preparation scans but records ShipmentPrepared only on the first', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-1');
    $delivery->recordPreparationScan('u-2', geo(), device(), at('2026-10-13T08:01:00+00:00'), 'k-2');

    $events = $delivery->releaseEvents();

    // Cada escaneo emite su UnitPrepared (uno por bulto); ShipmentPrepared sale SOLO en el primero: para dos
    // bultos son [UnitPrepared, ShipmentPrepared, UnitPrepared].
    $prepared = array_filter($events, static fn (object $event): bool => $event instanceof ShipmentPrepared);
    $units = array_filter($events, static fn (object $event): bool => $event instanceof UnitPrepared);

    expect($delivery->scans())->toHaveCount(2)
        ->and($delivery->status())->toBe(DeliveryStatus::Prepared)
        ->and($events)->toHaveCount(3)
        ->and($prepared)->toHaveCount(1)
        ->and($units)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(UnitPrepared::class)
        ->and($events[1])->toBeInstanceOf(ShipmentPrepared::class)
        ->and($events[2])->toBeInstanceOf(UnitPrepared::class);
});

it('emits one UnitPrepared per scanned unit and ShipmentPrepared only on the first', function (): void {
    $delivery = newDelivery();

    foreach (['u-1', 'u-2', 'u-3'] as $index => $unit) {
        $delivery->recordPreparationScan($unit, geo(), device(), at("2026-10-13T08:0{$index}:00+00:00"), 'k-prep-'.$unit);
    }

    $events = $delivery->releaseEvents();
    $units = array_filter($events, static fn (object $event): bool => $event instanceof UnitPrepared);
    $prepared = array_filter($events, static fn (object $event): bool => $event instanceof ShipmentPrepared);

    expect($units)->toHaveCount(3)
        ->and($prepared)->toHaveCount(1)
        ->and($events)->toHaveCount(4);
});

it('emits one UnitLoaded per scanned unit and ShipmentLoaded only on the first', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->releaseEvents();

    foreach (['u-1', 'u-2', 'u-3'] as $index => $unit) {
        $delivery->recordLoadScan($unit, geo(), device(), at("2026-10-13T09:0{$index}:00+00:00"), 'k-load-'.$unit);
    }

    $events = $delivery->releaseEvents();
    $units = array_filter($events, static fn (object $event): bool => $event instanceof UnitLoaded);
    $loaded = array_filter($events, static fn (object $event): bool => $event instanceof ShipmentLoaded);

    expect($units)->toHaveCount(3)
        ->and($loaded)->toHaveCount(1)
        ->and($events)->toHaveCount(4);
});

it('is idempotent by idempotency key: a repeated scan neither duplicates nor re-emits', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'same-key');
    $delivery->releaseEvents();

    // Reenvío offline del mismo escaneo: misma clave.
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'same-key');

    expect($delivery->scans())->toHaveCount(1)
        ->and($delivery->releaseEvents())->toBe([]);
});

it('records a failed delivery with its typed reason', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    // El motivo es ahora un code del catálogo configurable (string), no un enum fijo; la nota es opcional.
    $delivery->recordFailed('CUSTOMER_ABSENT', 'customer not at home', 'u-1', geo(), device(), at('2026-10-13T12:00:00+00:00'), 'k-fail');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::Failed)
        ->and($delivery->failureReason())->toBe('CUSTOMER_ABSENT')
        ->and($delivery->failureNote())->toBe('customer not at home')
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DeliveryFailed::class);

    /** @var DeliveryFailed $failed */
    $failed = $events[0];
    expect($failed->payload()['reason'])->toBe('CUSTOMER_ABSENT')
        ->and($failed->payload()['note'])->toBe('customer not at home');
});

it('records a node reception and moves the delivery to InTransit', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    $delivery->recordNodeReception('n-5', 'u-1', geo(), device(), at('2026-10-13T10:00:00+00:00'), 'k-node');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::InTransit)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeReceptionRecorded::class);

    /** @var NodeReceptionRecorded $node */
    $node = $events[0];
    expect($node->payload()['node_id'])->toBe('n-5');
});

it('permite la prueba de entrega desde Prepared (flujo sin escaneo de carga): entrega directo', function (): void {
    // En el flujo vigente el app ya no manda escaneo de carga, así que el POD vale sin pasar por Loaded.
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');

    $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR', null, at('2026-10-13T12:00:00+00:00')),
        geo(),
        device(),
        at('2026-10-13T12:00:00+00:00'),
        'k-pod',
    );

    expect($delivery->status())->toBe(DeliveryStatus::Delivered);
});

it('is idempotent on an already-delivered delivery (re-POD is a no-op)', function (): void {
    $delivery = Delivery::reconstitute(
        newDelivery()->id(),
        's-closed',
        'v-closed',
        DeliveryStatus::Delivered,
        [],
        null,
        null,
    );

    // Un POD sobre una entrega YA entregada (p. ej. la cola offline reintenta con otra clave) es un no-op
    // EXITOSO, no un error de transición (decisión del dueño: un reintento no debe figurar como fallo).
    $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR', null, at('2026-10-13T12:00:00+00:00')),
        geo(),
        device(),
        at('2026-10-13T12:00:00+00:00'),
        'k-pod',
    );

    expect($delivery->status())->toBe(DeliveryStatus::Delivered);
});

it('rejects loading a delivery that was never prepared', function (): void {
    $delivery = newDelivery();

    expect(fn () => $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load'))
        ->toThrow(ExecutionException::class, 'transición inválida');
});

it('reconstitutes without recording events', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->releaseEvents();

    $reloaded = Delivery::reconstitute(
        $delivery->id(),
        $delivery->shipmentId(),
        $delivery->vehicleId(),
        $delivery->status(),
        $delivery->scans(),
        $delivery->proofOfDelivery(),
        $delivery->failureReason(),
    );

    expect($reloaded->releaseEvents())->toBe([])
        ->and($reloaded->status())->toBe(DeliveryStatus::Prepared)
        ->and($reloaded->scans())->toHaveCount(1)
        ->and($reloaded->id()->equals($delivery->id()))->toBeTrue();
});

it('marks departure from Loaded to InTransit and records ShipmentDeparted', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    $delivery->markDeparted(at('2026-10-13T10:00:00+00:00'));

    expect($delivery->status())->toBe(DeliveryStatus::InTransit);
    $events = $delivery->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentDeparted::class)
        ->and($events[0]->eventName())->toBe('execution.shipment_departed')
        ->and($events[0]->payload()['shipment_id'])->toBe($delivery->shipmentId());
});

it('departure is idempotent: no event when not Loaded', function (): void {
    $delivery = newDelivery();
    // Pending (no cargada): la salida no aplica y no reemite.
    $delivery->markDeparted(at('2026-10-13T10:00:00+00:00'));

    expect($delivery->status())->toBe(DeliveryStatus::Pending)
        ->and($delivery->releaseEvents())->toBe([]);
});
