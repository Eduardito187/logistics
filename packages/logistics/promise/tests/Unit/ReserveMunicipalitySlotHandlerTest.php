<?php

declare(strict_types=1);

use Logistics\Promise\Application\Command\ReserveMunicipalitySlot\ReserveMunicipalitySlot;
use Logistics\Promise\Application\Command\ReserveMunicipalitySlot\ReserveMunicipalitySlotHandler;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchGate;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\MunicipalitySlotReserver;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/*
| K-03 — Selección de slot por el cliente. Prueban que el handler:
|  - consume cupo del municipio y fija la franja en el envío de forma atómica;
|  - es idempotente: reservar la MISMA franja ya vigente no vuelve a consumir cupo;
|  - reprograma: libera la franja anterior y toma la nueva;
|  - rechaza un día no despachable con "día no disponible" (422) SIN consumir cupo;
|  - rechaza una franja agotada con "franja ya tomada" (409).
*/

/** Puerta de despacho falsa: un veredicto fijo (día despachable o no). */
function muniReserveGate(bool $dispatchable): MunicipalityDispatchGate
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

/** Resolver de municipio falso para la reserva. */
function muniReserveResolver(?string $municipalityId): MunicipalityResolver
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

/** Localizador de envío falso (coordenada fija) para la reserva. */
function muniReserveShipmentLocator(): ShipmentLocator
{
    return new class implements ShipmentLocator
    {
        public function locate(string $shipmentId): ?array
        {
            return ['latitude' => -16.5, 'longitude' => -68.1];
        }
    };
}

/** Reservador de cupo falso que registra las llamadas de reserve/release y responde un éxito configurable. */
function muniReserveReserver(bool $reserveSucceeds): MunicipalitySlotReserver
{
    return new class($reserveSucceeds) implements MunicipalitySlotReserver
    {
        /** @var list<array{municipality: string, slot: string}> */
        public array $reserved = [];

        /** @var list<array{municipality: string, slot: string}> */
        public array $released = [];

        public function __construct(private readonly bool $reserveSucceeds) {}

        public function reserve(string $municipalityId, DeliverySlot $slot): bool
        {
            $this->reserved[] = ['municipality' => $municipalityId, 'slot' => $slot->toCanonical()];

            return $this->reserveSucceeds;
        }

        public function release(string $municipalityId, DeliverySlot $slot): void
        {
            $this->released[] = ['municipality' => $municipalityId, 'slot' => $slot->toCanonical()];
        }
    };
}

/**
 * Pasarela de envío falsa: devuelve la franja vigente configurada y registra la que se le asigne.
 *
 * @param array{date: string, window_start: string, window_end: string}|null $current
 */
function muniReserveOrders(?array $current): OrderDeliverySlotGateway
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

function muniReserveHandler(
    MunicipalityResolver $resolver,
    MunicipalityDispatchGate $gate,
    MunicipalitySlotReserver $reserver,
    OrderDeliverySlotGateway $orders,
): ReserveMunicipalitySlotHandler {
    return new ReserveMunicipalitySlotHandler(
        new CustomerLocationResolver($resolver, muniReserveShipmentLocator()),
        $gate,
        $reserver,
        $orders,
    );
}

const MUNI_RESERVE_SHIPMENT = '0192f0a0-0000-7000-8000-00000000aaaa';

it('consumes municipality capacity and fixes the slot on the shipment', function (): void {
    $reserver = muniReserveReserver(true);
    $orders = muniReserveOrders(null);
    $handler = muniReserveHandler(muniReserveResolver('muni-a'), muniReserveGate(true), $reserver, $orders);

    $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '09:00',
        windowEnd: '13:00',
    ));

    expect($reserver->reserved)->toHaveCount(1)
        ->and($reserver->reserved[0]['municipality'])->toBe('muni-a')
        ->and($reserver->reserved[0]['slot'])->toBe('2026-11-20|09:00-13:00')
        ->and($reserver->released)->toBe([])
        ->and($orders->assigned)->toHaveCount(1)
        ->and($orders->assigned[0])->toBe([
            'order' => MUNI_RESERVE_SHIPMENT,
            'date' => '2026-11-20',
            'start' => '09:00',
            'end' => '13:00',
        ]);
});

it('is idempotent: reserving the same slot again does not consume capacity', function (): void {
    $reserver = muniReserveReserver(true);
    $orders = muniReserveOrders(['date' => '2026-11-20', 'window_start' => '09:00', 'window_end' => '13:00']);
    $handler = muniReserveHandler(muniReserveResolver('muni-a'), muniReserveGate(true), $reserver, $orders);

    $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '09:00',
        windowEnd: '13:00',
    ));

    expect($reserver->reserved)->toBe([])
        ->and($reserver->released)->toBe([])
        ->and($orders->assigned)->toBe([]);
});

it('reschedules: releases the previous slot before taking the new one', function (): void {
    $reserver = muniReserveReserver(true);
    $orders = muniReserveOrders(['date' => '2026-11-20', 'window_start' => '09:00', 'window_end' => '13:00']);
    $handler = muniReserveHandler(muniReserveResolver('muni-a'), muniReserveGate(true), $reserver, $orders);

    $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '14:00',
        windowEnd: '18:00',
    ));

    expect($reserver->released)->toHaveCount(1)
        ->and($reserver->released[0]['slot'])->toBe('2026-11-20|09:00-13:00')
        ->and($reserver->reserved)->toHaveCount(1)
        ->and($reserver->reserved[0]['slot'])->toBe('2026-11-20|14:00-18:00');
});

it('rejects a non-dispatchable day as day_unavailable without consuming capacity', function (): void {
    $reserver = muniReserveReserver(true);
    $orders = muniReserveOrders(null);
    $handler = muniReserveHandler(muniReserveResolver('muni-a'), muniReserveGate(false), $reserver, $orders);

    $call = fn () => $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '09:00',
        windowEnd: '13:00',
    ));

    expect($call)->toThrow(PromiseException::class);
    expect($reserver->reserved)->toBe([])
        ->and($orders->assigned)->toBe([]);

    try {
        $call();
    } catch (PromiseException $exception) {
        expect($exception->kind())->toBe(PromiseException::KIND_DAY_UNAVAILABLE);
    }
});

it('rejects an exhausted slot as slot_taken (409) when the day is dispatchable', function (): void {
    $reserver = muniReserveReserver(false); // cupo agotado
    $orders = muniReserveOrders(null);
    $handler = muniReserveHandler(muniReserveResolver('muni-a'), muniReserveGate(true), $reserver, $orders);

    $call = fn () => $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '09:00',
        windowEnd: '13:00',
    ));

    expect($call)->toThrow(PromiseException::class);
    expect($orders->assigned)->toBe([]); // no se fija la franja si no hubo cupo

    try {
        $call();
    } catch (PromiseException $exception) {
        expect($exception->kind())->toBe(PromiseException::KIND_SLOT_TAKEN);
    }
});

it('fails when the municipality cannot be resolved for the shipment', function (): void {
    $reserver = muniReserveReserver(true);
    $orders = muniReserveOrders(null);
    $handler = muniReserveHandler(muniReserveResolver(null), muniReserveGate(true), $reserver, $orders);

    $call = fn () => $handler->handle(new ReserveMunicipalitySlot(
        shipmentId: MUNI_RESERVE_SHIPMENT,
        date: '2026-11-20',
        windowStart: '09:00',
        windowEnd: '13:00',
    ));

    expect($call)->toThrow(PromiseException::class);
    expect($reserver->reserved)->toBe([]);
});
