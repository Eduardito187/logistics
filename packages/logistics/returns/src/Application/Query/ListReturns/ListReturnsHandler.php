<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Query\ListReturns;

use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista devoluciones con los filtros dados, como ReadModels.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista (tope sano del repositorio), por compatibilidad.
 *
 * @implements QueryHandler<ListReturns, list<ReturnView>|Page<ReturnView>>
 */
final readonly class ListReturnsHandler implements QueryHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
    ) {}

    /**
     * @return list<ReturnView>|Page<ReturnView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListReturns);

        $views = array_map(
            static fn (ReturnOrder $returnOrder): ReturnView => ReturnView::fromDomain($returnOrder),
            $this->returns->listBy($query->sellerId, $query->status, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->returns->countBy($query->sellerId, $query->status));
    }
}
