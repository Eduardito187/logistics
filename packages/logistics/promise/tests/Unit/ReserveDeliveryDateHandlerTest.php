<?php

declare(strict_types=1);

use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDateHandler;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\Service\WindowSelector;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Shared\Domain\Clock\Clock;

/*
| Módulo 1 — Reserva por fecha (el cliente solo elige el día; el sistema asigna la ventana). Prueban que:
|  - elige la ventana, consume cupo, fija la franja y emite promise.delivery_window_assigned;
|  - es idempotente por (envío, fecha);
|  - reprograma de día liberando el cupo del día anterior;
|  - rechaza día no despachable (422) y "sin ventana" cuando no hay cupo factible;
|  - honra la preferencia horaria del cliente.
*/

const RDD_SHIPMENT = '0192f0a0-0000-7000-8000-0000000000dd';

/** Resolver de municipio falso. */
function rddResolver(?string $municipalityId): MunicipalityResolver
{
    return new class($municipalityId) implements MunicipalityResolver
    {
        public function __construct(private readonly ?string $municipalityId) {}

        public function resolve(float $latitude, float $longitude): ?string
        {
            return $this->municipalityId;
        }
    };
}

/** Localizador de envío falso (coordenada fija). */
function rddShipmentLocator(): ShipmentLocator
{
    return new class implements ShipmentLocator
    {
        public function locate(string $shipmentId): ?array
        {
            return ['latitude' => -16.5, 'longitude' => -68.1];
        }
    };
}

/** Puerta de despacho falsa con veredicto fijo. */
function rddGate(bool $dispatchable): MunicipalityDispatchGate
{
    return new class($dispatchable) implements MunicipalityDispatchGate
    {
        public function __construct(private readonly bool $dispatchable) {}

        public function isDispatchable(?string $municipalityId, DateTimeImmutable $date): bool
        {
            return $this->dispatchable;
        }
    };
}

/**
 * Calendario de municipio falso que devuelve las franjas dadas (etiqueta => cupo).
 *
 * @param array<string, float> $slots
 */
function rddCalendar(array $slots): MunicipalityDispatchCalendar
{
    return new class($slots) implements MunicipalityDispatchCalendar
    {
        /** @param array<string, float> $slots */
        public function __construct(private readonly array $slots) {}

        public function availableSlots(string $municipalityId, DateTimeImmutable $date): array
        {
            $capacities = [];
            foreach ($this->slots as $label => $available) { // NOSONAR: FP de data-flow; el foreach inicializa label/available.
                $capacities[] = SlotCapacity::of((string) $label, $available);
            }

            return $capacities;
        }
    };
}

/** Reservador de cupo falso que registra reserve/release y responde un éxito configurable. */
function rddReserver(bool $reserveSucceeds): MunicipalitySlotReserver
{
    return new class($reserveSucceeds) implements MunicipalitySlotReserver
    {
        /** @var list<string> */
        public array $reserved = [];

        /** @var list<string> */
        public array $released = [];

        public function __construct(private readonly bool $reserveSucceeds) {}

        public function reserve(string $municipalityId, DeliverySlot $slot): bool
        {
            $this->reserved[] = $slot->toCanonical();

            return $this->reserveSucceeds;
        }

        public function release(string $municipalityId, DeliverySlot $slot): void
        {
            $this->released[] = $slot->toCanonical();
        }
    };
}

/**
 * Pasarela de envío falsa: franja vigente configurada + registro de la asignada.
 *
 * @param array{date: string, window_start: string, window_end: string}|null $current
 */
function rddOrders(?array $current): OrderDeliverySlotGateway
{
    return new class($current) implements OrderDeliverySlotGateway
    {
        /** @var list<array{order: string, date: string, start: string, end: string}> */
        public array $assigned = [];

        /** @param array{date: string, window_start: string, window_end: string}|null $current */
        public function __construct(private readonly ?array $current) {}

        public function currentSlot(string $orderId): ?array
        {
            return $this->current;
        }

        public function assignSlot(string $orderId, string $date, string $windowStart, string $windowEnd): void
        {
            $this->assigned[] = ['order' => $orderId, 'date' => $date, 'start' => $windowStart, 'end' => $windowEnd];
        }
    };
}

/** Outbox falso que captura los eventos grabados (nombre + payload). */
function rddEvents(): RecordDomainEvents
{
    $store = new class implements OutboxStore
    {
        /** @var list<array{name: string, payload: array<string, mixed>}> */
        public array $recorded = [];

        public function append(OutboxMessage $message): void
        {
            $this->recorded[] = ['name' => $message->eventName(), 'payload' => $message->payload()];
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-12 00:00:00');
        }
    };

    return new RecordDomainEvents($store, $clock);
}

/**
 * @param array<string, float> $slots
 */
function rddHandler(
    MunicipalityDispatchGate $gate,
    array $slots,
    MunicipalitySlotReserver $reserver,
    OrderDeliverySlotGateway $orders,
    RecordDomainEvents $events,
    ?string $municipalityId = 'muni-a',
): ReserveDeliveryDateHandler {
    return new ReserveDeliveryDateHandler(
        new CustomerLocationResolver(rddResolver($municipalityId), rddShipmentLocator()),
        $gate,
        rddCalendar($slots),
        $reserver,
        $orders,
        new WindowSelector,
        $events,
        new ConfigRepository([]),
    );
}

it('elige la ventana, consume cupo y fija la franja en el envío', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(null);

    rddHandler(rddGate(true), ['09:00-13:00' => 5.0, '14:00-18:00' => 3.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    // Sin preferencia, con más cupo y más temprana, gana la franja de la mañana.
    expect($reserver->reserved)->toBe(['2026-11-20|09:00-13:00'])
        ->and($reserver->released)->toBe([])
        ->and($orders->assigned)->toBe([[
            'order' => RDD_SHIPMENT,
            'date' => '2026-11-20',
            'start' => '09:00',
            'end' => '13:00',
        ]]);
});

it('elige y emite el evento de ventana asignada', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(null);

    // Store observable: se captura por referencia para leer lo grabado.
    $store = new class implements OutboxStore
    {
        /** @var list<array{name: string, payload: array<string, mixed>}> */
        public array $recorded = [];

        public function append(OutboxMessage $message): void
        {
            $this->recorded[] = ['name' => $message->eventName(), 'payload' => $message->payload()];
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };
    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-12 00:00:00');
        }
    };

    $handler = new ReserveDeliveryDateHandler(
        new CustomerLocationResolver(rddResolver('muni-a'), rddShipmentLocator()),
        rddGate(true),
        rddCalendar(['09:00-13:00' => 5.0]),
        $reserver,
        $orders,
        new WindowSelector,
        new RecordDomainEvents($store, $clock),
        new ConfigRepository([]),
    );

    $handler->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($store->recorded)->toHaveCount(1)
        ->and($store->recorded[0]['name'])->toBe('promise.delivery_window_assigned')
        ->and($store->recorded[0]['payload']['slot_date'])->toBe('2026-11-20')
        ->and($store->recorded[0]['payload']['window_start'])->toBe('09:00')
        ->and($store->recorded[0]['payload']['window_end'])->toBe('13:00')
        ->and($store->recorded[0]['payload']['slot_source'])->toBe('auto');
});

it('honra la preferencia horaria del cliente (tarde) aunque la mañana tenga más cupo', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(null);

    rddHandler(rddGate(true), ['09:00-13:00' => 10.0, '14:00-18:00' => 1.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(
            shipmentId: RDD_SHIPMENT,
            date: '2026-11-20',
            preference: ['kind' => 'afternoon'],
        ));

    expect($reserver->reserved)->toBe(['2026-11-20|14:00-18:00']);
});

it('es idempotente por (envío, fecha): mismo día no reconsume cupo', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(['date' => '2026-11-20', 'window_start' => '09:00', 'window_end' => '13:00']);

    rddHandler(rddGate(true), ['09:00-13:00' => 5.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($reserver->reserved)->toBe([])
        ->and($reserver->released)->toBe([])
        ->and($orders->assigned)->toBe([]);
});

it('reprograma de día: libera el cupo del día anterior antes de tomar el nuevo', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(['date' => '2026-11-19', 'window_start' => '14:00', 'window_end' => '18:00']);

    rddHandler(rddGate(true), ['09:00-13:00' => 5.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($reserver->released)->toBe(['2026-11-19|14:00-18:00'])
        ->and($reserver->reserved)->toBe(['2026-11-20|09:00-13:00']);
});

it('rechaza un día no despachable como day_unavailable sin consumir cupo', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(null);

    $call = fn () => rddHandler(rddGate(false), ['09:00-13:00' => 5.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($call)->toThrow(PromiseException::class);
    expect($reserver->reserved)->toBe([])
        ->and($orders->assigned)->toBe([]);

    try {
        $call();
    } catch (PromiseException $exception) {
        expect($exception->kind())->toBe(PromiseException::KIND_DAY_UNAVAILABLE);
    }
});

it('rechaza "sin ventana" cuando el día es despachable pero ninguna franja tiene cupo', function (): void {
    $reserver = rddReserver(true);
    $orders = rddOrders(null);

    $call = fn () => rddHandler(rddGate(true), ['09:00-13:00' => 0.0], $reserver, $orders, rddEvents())
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($call)->toThrow(PromiseException::class);
    expect($reserver->reserved)->toBe([]);

    try {
        $call();
    } catch (PromiseException $exception) {
        expect($exception->kind())->toBe(PromiseException::KIND_NO_WINDOW);
    }
});

it('falla cuando no se puede resolver el municipio del envío', function (): void {
    $reserver = rddReserver(true);

    $call = fn () => rddHandler(rddGate(true), ['09:00-13:00' => 5.0], $reserver, rddOrders(null), rddEvents(), null)
        ->handle(new ReserveDeliveryDate(shipmentId: RDD_SHIPMENT, date: '2026-11-20'));

    expect($call)->toThrow(PromiseException::class);
    expect($reserver->reserved)->toBe([]);
});
