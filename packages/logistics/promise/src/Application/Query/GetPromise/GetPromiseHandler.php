<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\GetPromise;

use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetPromise, ?PromiseView>
 */
final class GetPromiseHandler implements QueryHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
    ) {}

    public function handle(Query $query): ?PromiseView
    {
        if (!$query instanceof GetPromise) {
            throw PromiseException::because('GetPromiseHandler solo maneja GetPromise');
        }

        $promise = $this->promises->findById(PromiseId::fromString($query->promiseId));

        return $promise === null ? null : PromiseView::fromPromise($promise);
    }
}
