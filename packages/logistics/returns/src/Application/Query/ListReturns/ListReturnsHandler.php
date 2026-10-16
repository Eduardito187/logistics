<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Query\ListReturns;

use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista devoluciones con los filtros dados, como ReadModels.
 *
 * @implements QueryHandler<ListReturns, list<ReturnView>>
 */
final readonly class ListReturnsHandler implements QueryHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
    ) {}

    /**
     * @return list<ReturnView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListReturns);

        $returns = $this->returns->listBy($query->sellerId, $query->status);

        return array_map(static fn (ReturnOrder $returnOrder): ReturnView => ReturnView::fromDomain($returnOrder), $returns);
    }
}
