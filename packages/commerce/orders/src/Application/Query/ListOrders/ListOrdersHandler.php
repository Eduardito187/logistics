<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Query\ListOrders;

use Commerce\Orders\Application\ReadModel\OrderView;
use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista órdenes (todas o por estado) como ReadModels.
 *
 * @implements QueryHandler<ListOrders, list<OrderView>>
 */
final readonly class ListOrdersHandler implements QueryHandler
{
    public function __construct(
        private OrderRepository $orders,
    ) {}

    /**
     * @return list<OrderView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListOrders);

        $orders = $query->status !== null
            ? $this->orders->listByStatus(OrderStatus::from($query->status))
            : $this->orders->all();

        return array_map(
            static fn (Order $order): OrderView => OrderView::fromDomain($order),
            $orders,
        );
    }
}
