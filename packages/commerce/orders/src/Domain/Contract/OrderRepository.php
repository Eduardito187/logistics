<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Contract;

use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderStatus;

/**
 * Puerto de persistencia de órdenes. La implementación vive en Infrastructure.
 */
interface OrderRepository
{
    public function nextIdentity(): OrderId;

    public function save(Order $order): void;

    public function findById(OrderId $id): ?Order;

    /**
     * @return list<Order>
     */
    public function all(): array;

    /**
     * @return list<Order>
     */
    public function listByStatus(OrderStatus $status): array;
}
