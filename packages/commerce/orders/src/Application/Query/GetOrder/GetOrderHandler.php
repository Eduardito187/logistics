<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Query\GetOrder;

use Commerce\Orders\Application\ReadModel\OrderView;
use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una orden por su id, o null si no existe.
 *
 * @implements QueryHandler<GetOrder, OrderView|null>
 */
final readonly class GetOrderHandler implements QueryHandler
{
    public function __construct(
        private OrderRepository $orders,
    ) {}

    public function handle(Query $query): ?OrderView
    {
        assert($query instanceof GetOrder);

        $order = $this->orders->findById(OrderId::fromString($query->orderId));

        return $order !== null ? OrderView::fromDomain($order) : null;
    }
}
