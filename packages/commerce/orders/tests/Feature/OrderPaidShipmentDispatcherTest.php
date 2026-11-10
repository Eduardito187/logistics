<?php

declare(strict_types=1);

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Domain\ValueObject\ShippingAddress;
use Commerce\Orders\Infrastructure\Dispatch\OrderPaidShipmentDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;

/**
 * El puente orders → shipments reparte una orden multishipping en un envío por destino: el grupo a
 * domicilio conserva external_id = orden (idempotencia/retrocompatibilidad) y cada grupo a nodo lleva un
 * external_id compuesto, pero todos comparten la referencia de orden (orderReference) que los agrupa.
 */

/** Bus que captura los comandos despachados, para inspeccionarlos. */
function capturingBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;
        }
    };
}

/** Repositorio que siempre devuelve la orden dada. */
function repoReturning(Order $order): OrderRepository
{
    return new class($order) implements OrderRepository
    {
        public function __construct(private readonly Order $order) {}

        public function nextIdentity(): OrderId
        {
            return OrderId::generate();
        }

        public function save(Order $order): void {}

        public function findById(OrderId $id): ?Order
        {
            return $this->order;
        }

        public function all(): array
        {
            return [$this->order];
        }

        public function listByStatus(OrderStatus $status): array
        {
            return [$this->order];
        }
    };
}

function orderPaidMessage(string $orderId): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'orders.order',
        $orderId,
        'orders.order_paid',
        [],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

it('reparte una orden a varios destinos en un envío por destino, bajo la misma orderReference', function (): void {
    $nodeId = Uuid::generate()->value;
    $order = Order::place(
        OrderId::generate(),
        null,
        ShippingAddress::of('Caro Cliente', 'Calle Hogar 1'),
        [
            OrderLine::of('SKU-HOME', 'Sofá', 450000, 1),                 // a domicilio
            OrderLine::of('SKU-PICK', 'Taladro', 120000, 2, $nodeId),      // retiro en nodo
        ],
    );
    $orderId = $order->id()->toString();

    $bus = capturingBus();
    (new OrderPaidShipmentDispatcher(repoReturning($order), $bus))->dispatch(orderPaidMessage($orderId));

    $received = array_values(array_filter($bus->dispatched, static fn (Command $c): bool => $c instanceof ReceiveShipment));
    expect($received)->toHaveCount(2);

    $byExternal = [];
    foreach ($received as $cmd) {
        assert($cmd instanceof ReceiveShipment);
        $byExternal[$cmd->externalId] = $cmd;
    }

    // Grupo domicilio: external_id = orden, sin nodo. Grupo nodo: external_id compuesto, con nodo.
    expect($byExternal)->toHaveKey($orderId)
        ->and($byExternal)->toHaveKey($orderId.'#n:'.$nodeId)
        ->and($byExternal[$orderId]->destinationNodeId)->toBeNull()
        ->and($byExternal[$orderId]->orderReference)->toBe($orderId)
        ->and($byExternal[$orderId.'#n:'.$nodeId]->destinationNodeId)->toBe($nodeId)
        ->and($byExternal[$orderId.'#n:'.$nodeId]->orderReference)->toBe($orderId);
});

it('una orden de un solo destino produce un único envío con external_id = orden (sin cambios)', function (): void {
    $order = Order::place(
        OrderId::generate(),
        null,
        ShippingAddress::of('Ana Cliente', 'Av. Siempre Viva 742'),
        [
            OrderLine::of('SKU-A', 'Taladro', 100000, 1),
            OrderLine::of('SKU-B', 'Brocas', 50000, 3),
        ],
    );
    $orderId = $order->id()->toString();

    $bus = capturingBus();
    (new OrderPaidShipmentDispatcher(repoReturning($order), $bus))->dispatch(orderPaidMessage($orderId));

    $received = array_values(array_filter($bus->dispatched, static fn (Command $c): bool => $c instanceof ReceiveShipment));
    expect($received)->toHaveCount(1)
        ->and($received[0]->externalId)->toBe($orderId)
        ->and($received[0]->destinationNodeId)->toBeNull()
        ->and($received[0]->orderReference)->toBe($orderId)
        ->and($received[0]->lines)->toHaveCount(2);
});
