<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ReleasePromiseReservation\ReleasePromiseReservation;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;
use Logistics\Promise\Infrastructure\Dispatch\OrderCancelledCupoReleaseDispatcher;
use Logistics\Promise\Infrastructure\Dispatch\OrderPaidCupoConfirmDispatcher;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/*
| Puentes comercio → promise del cupo pre-pago (ADR 020). Son adaptadores de Infrastructure PHP puros:
| hallan por `shipment_ref = order_id` las promesas que tienen cupo tomado y despachan el comando que
| corresponde (confirmar al pagar / liberar al cancelar). Se ejercitan con un repositorio en memoria y un
| CommandBus que captura lo despachado; no tocan la base.
*/

/** Promesa reconstituida con el order_id como shipment_ref y el estado dado. */
function cupoPromise(string $orderId, PromiseStatus $status): DeliveryPromise
{
    return DeliveryPromise::reconstitute(
        PromiseId::generate(),
        $orderId,
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), '09:00-13:00'),
        $status,
        $status === PromiseStatus::Reserved
            ? ReservationWindow::until(new DateTimeImmutable('2026-10-21T00:00:00'))
            : null,
    );
}

/**
 * Repositorio en memoria sembrado con promesas; holdingByShipmentRef filtra por ref + cupo tomado.
 *
 * @param list<DeliveryPromise> $seed
 */
function cupoRepo(array $seed): PromiseRepository
{
    return new class($seed) implements PromiseRepository
    {
        /** @var array<string, DeliveryPromise> */
        public array $store = [];

        /** @param list<DeliveryPromise> $seed */
        public function __construct(array $seed)
        {
            foreach ($seed as $promise) { // NOSONAR: $promise es la variable del foreach (list<DeliveryPromise>), siempre inicializada; falso positivo del data-flow sobre la clase anónima.
                $this->store[$promise->id()->toString()] = $promise;
            }
        }

        public function nextIdentity(): PromiseId
        {
            return PromiseId::generate();
        }

        public function save(DeliveryPromise $promise): void
        {
            $this->store[$promise->id()->toString()] = $promise;
        }

        public function findById(PromiseId $id): ?DeliveryPromise
        {
            return $this->store[$id->toString()] ?? null;
        }

        public function reservedExpiredAt(DateTimeImmutable $now): array
        {
            return [];
        }

        public function holdingByShipmentRef(string $shipmentRef): array
        {
            return array_values(array_map(
                static fn (DeliveryPromise $p): PromiseId => $p->id(),
                array_values(array_filter(
                    $this->store,
                    static fn (DeliveryPromise $p): bool => $p->shipmentRef() === $shipmentRef && $p->holdsCapacity(),
                )),
            ));
        }
    };
}

/**
 * CommandBus que captura los comandos despachados. Si se le da un $throwFor, lanza PromiseException al
 * recibir un comando de esa clase (para probar la tolerancia del reactor de confirmación).
 *
 * @param class-string<Command>|null $throwFor
 */
function cupoBus(?string $throwFor = null): CommandBus
{
    return new class($throwFor) implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        /** @param class-string<Command>|null $throwFor */
        public function __construct(private readonly ?string $throwFor) {}

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;

            if ($this->throwFor !== null && $command instanceof $this->throwFor) {
                throw PromiseException::because('simula una promesa ya confirmada');
            }
        }
    };
}

/** Envuelve un evento de orden en un OutboxMessage, como lo recibe el reactor en el relay. */
function cupoOutbox(string $eventName, string $orderId, bool $payloadCarriesId): OutboxMessage
{
    $event = new readonly class($eventName, $orderId, $payloadCarriesId) implements DomainEvent
    {
        public function __construct(
            private string $name,
            private string $orderId,
            private bool $payloadCarriesId,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return $this->orderId;
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
            return $this->payloadCarriesId ? ['order_id' => $this->orderId] : ['gateway_ref' => 'gw-1'];
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'commerce.order', new DateTimeImmutable('2026-10-18T10:00:00+00:00'));
}

// ---------------------------------------------------------------------------
// OrderPaidCupoConfirmDispatcher
// ---------------------------------------------------------------------------

it('confirma el cupo reservado de la orden al pagar (order_id como aggregateId)', function (): void {
    $orderId = '0192f0a0-0000-7000-8000-00000000aaaa';
    $promise = cupoPromise($orderId, PromiseStatus::Reserved);
    $bus = cupoBus();
    $dispatcher = new OrderPaidCupoConfirmDispatcher(cupoRepo([$promise]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_paid', $orderId, payloadCarriesId: false));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(ConfirmPromise::class)
        ->and($bus->dispatched[0]->promiseId)->toBe($promise->id()->toString());
});

it('no confirma nada si la orden no reservó cupo', function (): void {
    $bus = cupoBus();
    $dispatcher = new OrderPaidCupoConfirmDispatcher(cupoRepo([]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_paid', 'order-sin-cupo', payloadCarriesId: false));

    expect($bus->dispatched)->toBe([]);
});

it('tolera que confirmar lance (reentrega / ya confirmada) sin propagar', function (): void {
    $orderId = 'order-x';
    $promise = cupoPromise($orderId, PromiseStatus::Reserved);
    $bus = cupoBus(throwFor: ConfirmPromise::class);
    $dispatcher = new OrderPaidCupoConfirmDispatcher(cupoRepo([$promise]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_paid', $orderId, payloadCarriesId: false));

    expect($bus->dispatched)->toHaveCount(1); // intentó, la excepción se tragó
});

it('ignora eventos que no son orders.order_paid', function (): void {
    $bus = cupoBus();
    $dispatcher = new OrderPaidCupoConfirmDispatcher(cupoRepo([cupoPromise('o1', PromiseStatus::Reserved)]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_placed', 'o1', payloadCarriesId: true));

    expect($bus->dispatched)->toBe([]);
});

// ---------------------------------------------------------------------------
// OrderCancelledCupoReleaseDispatcher
// ---------------------------------------------------------------------------

it('libera el cupo al cancelar una orden con reserva (pre-pago)', function (): void {
    $orderId = 'order-cancel-1';
    $promise = cupoPromise($orderId, PromiseStatus::Reserved);
    $bus = cupoBus();
    $dispatcher = new OrderCancelledCupoReleaseDispatcher(cupoRepo([$promise]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_cancelled', $orderId, payloadCarriesId: true));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(ReleasePromiseReservation::class)
        ->and($bus->dispatched[0]->promiseId)->toBe($promise->id()->toString());
});

it('libera el cupo al cancelar una orden ya CONFIRMADA (post-pago)', function (): void {
    $orderId = 'order-cancel-2';
    $promise = cupoPromise($orderId, PromiseStatus::Confirmed);
    $bus = cupoBus();
    $dispatcher = new OrderCancelledCupoReleaseDispatcher(cupoRepo([$promise]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_cancelled', $orderId, payloadCarriesId: true));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(ReleasePromiseReservation::class);
});

it('no libera nada si la orden cancelada no tenía cupo', function (): void {
    $bus = cupoBus();
    $dispatcher = new OrderCancelledCupoReleaseDispatcher(cupoRepo([]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_cancelled', 'order-sin-cupo', payloadCarriesId: true));

    expect($bus->dispatched)->toBe([]);
});

it('ignora eventos que no son orders.order_cancelled', function (): void {
    $bus = cupoBus();
    $dispatcher = new OrderCancelledCupoReleaseDispatcher(cupoRepo([cupoPromise('o1', PromiseStatus::Reserved)]), $bus);

    $dispatcher->dispatch(cupoOutbox('orders.order_paid', 'o1', payloadCarriesId: false));

    expect($bus->dispatched)->toBe([]);
});
