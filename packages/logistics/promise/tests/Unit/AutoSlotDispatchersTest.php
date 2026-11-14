<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Infrastructure\Dispatch\AutoSlotOnOrderPaid;
use Logistics\Promise\Infrastructure\Dispatch\AutoSlotOnShipmentReceived;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/*
| Reactores de auto-slot (Módulo 1, Fase 1.1). Son adaptadores de Infrastructure PHP puros: leen la fecha
| pendiente del envío por el puerto OrderDeliverySlotGateway y, si la hay, despachan ReserveDeliveryDate.
| Se ejercitan con un gateway en memoria y un CommandBus que captura lo despachado; no tocan la base.
*/

const ASD_SHIPMENT = '0192f0a0-0000-7000-8000-00000000asd1';
const ASD_DATE = '2026-10-20';

/**
 * Gateway en memoria: devuelve la fecha pendiente configurada por envío (null = sin fecha o ya con ventana).
 *
 * @param array<string, string|null> $pending
 */
function asdGateway(array $pending): OrderDeliverySlotGateway
{
    return new class($pending) implements OrderDeliverySlotGateway
    {
        /** @param array<string, string|null> $pending */
        public function __construct(private array $pending) {}

        public function currentSlot(string $orderId): ?array
        {
            return null;
        }

        public function pendingDeliveryDate(string $orderId): ?string
        {
            return $this->pending[$orderId] ?? null;
        }

        public function assignSlot(string $orderId, string $date, string $windowStart, string $windowEnd): void {}
    };
}

/**
 * CommandBus que captura los comandos; si recibe $throw, lanza esa PromiseException (día no despachable).
 */
function asdBus(?PromiseException $throw = null): CommandBus
{
    return new class($throw) implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function __construct(private readonly ?PromiseException $throw) {}

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;

            if ($this->throw !== null) {
                throw $this->throw;
            }
        }
    };
}

/** Envuelve un evento en un OutboxMessage, como lo recibe el reactor en el relay. */
function asdOutbox(string $eventName, string $shipmentId, string $payloadIdKey): OutboxMessage
{
    $event = new readonly class($eventName, $shipmentId, $payloadIdKey) implements DomainEvent
    {
        public function __construct(
            private string $name,
            private string $shipmentId,
            private string $payloadIdKey,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return $this->shipmentId;
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-18T10:00:00+00:00');
        }

        public function payload(): array
        {
            return [$this->payloadIdKey => $this->shipmentId];
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'commerce.order', new DateTimeImmutable('2026-10-18T10:00:00+00:00'));
}

// ---------------------------------------------------------------------------
// AutoSlotOnOrderPaid
// ---------------------------------------------------------------------------

it('auto-slotea un envío con fecha y sin ventana al pagar la orden', function (): void {
    $bus = asdBus();
    $dispatcher = new AutoSlotOnOrderPaid(asdGateway([ASD_SHIPMENT => ASD_DATE]), $bus);

    $dispatcher->dispatch(asdOutbox('orders.order_paid', ASD_SHIPMENT, 'order_id'));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(ReserveDeliveryDate::class)
        ->and($bus->dispatched[0]->shipmentId)->toBe(ASD_SHIPMENT)
        ->and($bus->dispatched[0]->date)->toBe(ASD_DATE);
});

it('no slotea si el envío no tiene fecha o ya tiene ventana (idempotente)', function (): void {
    $bus = asdBus();
    $dispatcher = new AutoSlotOnOrderPaid(asdGateway([ASD_SHIPMENT => null]), $bus);

    $dispatcher->dispatch(asdOutbox('orders.order_paid', ASD_SHIPMENT, 'order_id'));

    expect($bus->dispatched)->toBe([]);
});

it('ignora eventos que no son orders.order_paid', function (): void {
    $bus = asdBus();
    $dispatcher = new AutoSlotOnOrderPaid(asdGateway([ASD_SHIPMENT => ASD_DATE]), $bus);

    $dispatcher->dispatch(asdOutbox('orders.order_placed', ASD_SHIPMENT, 'order_id'));

    expect($bus->dispatched)->toBe([]);
});

it('tolera que la reserva lance (día no despachable / sin ventana) sin propagar', function (): void {
    $bus = asdBus(PromiseException::dayUnavailable('día bloqueado'));
    $dispatcher = new AutoSlotOnOrderPaid(asdGateway([ASD_SHIPMENT => ASD_DATE]), $bus);

    $dispatcher->dispatch(asdOutbox('orders.order_paid', ASD_SHIPMENT, 'order_id'));

    expect($bus->dispatched)->toHaveCount(1); // intentó, la excepción se tragó (queda en la cola Unslotted)
});

// ---------------------------------------------------------------------------
// AutoSlotOnShipmentReceived
// ---------------------------------------------------------------------------

it('auto-slotea un envío recibido con fecha y sin ventana', function (): void {
    $bus = asdBus();
    $dispatcher = new AutoSlotOnShipmentReceived(asdGateway([ASD_SHIPMENT => ASD_DATE]), $bus);

    $dispatcher->dispatch(asdOutbox('shipments.shipment_received', ASD_SHIPMENT, 'shipment_id'));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(ReserveDeliveryDate::class)
        ->and($bus->dispatched[0]->shipmentId)->toBe(ASD_SHIPMENT);
});

it('ignora eventos que no son shipments.shipment_received', function (): void {
    $bus = asdBus();
    $dispatcher = new AutoSlotOnShipmentReceived(asdGateway([ASD_SHIPMENT => ASD_DATE]), $bus);

    $dispatcher->dispatch(asdOutbox('shipments.shipment_rescheduled', ASD_SHIPMENT, 'shipment_id'));

    expect($bus->dispatched)->toBe([]);
});
