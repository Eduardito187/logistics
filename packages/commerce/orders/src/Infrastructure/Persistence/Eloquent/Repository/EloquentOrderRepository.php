<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Mapper\OrderMapper;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;

/**
 * Implementación Eloquent del repositorio de órdenes. `save` es un upsert por id.
 */
final readonly class EloquentOrderRepository implements OrderRepository
{
    public function __construct(
        private OrderMapper $mapper = new OrderMapper,
    ) {}

    public function nextIdentity(): OrderId
    {
        return OrderId::generate();
    }

    public function save(Order $order): void
    {
        $row = $this->mapper->toRow($order);
        OrderRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(OrderId $id): ?Order
    {
        $record = OrderRecord::query()->find($id->toString());

        return $record instanceof OrderRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(OrderRecord::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrderRecord $record): Order => $this->mapper->toDomain($record))
            ->all());
    }

    public function listByStatus(OrderStatus $status): array
    {
        return array_values(OrderRecord::query()
            ->where('status', $status->value)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OrderRecord $record): Order => $this->mapper->toDomain($record))
            ->all());
    }
}
