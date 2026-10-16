<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Query\GetReturn;

use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una devolución por su id, o null si no existe.
 *
 * @implements QueryHandler<GetReturn, ReturnView|null>
 */
final readonly class GetReturnHandler implements QueryHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
    ) {}

    public function handle(Query $query): ?ReturnView
    {
        assert($query instanceof GetReturn);

        $returnOrder = $this->returns->findById(ReturnId::fromString($query->returnId));

        return $returnOrder !== null ? ReturnView::fromDomain($returnOrder) : null;
    }
}
