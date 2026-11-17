<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListFailureReasons;

use Logistics\Execution\Application\ReadModel\FailureReasonView;
use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListFailureReasons, list<FailureReasonView>>
 */
final class ListFailureReasonsHandler implements QueryHandler
{
    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    /**
     * @return list<FailureReasonView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListFailureReasons) {
            throw ExecutionException::because('ListFailureReasonsHandler solo maneja ListFailureReasons');
        }

        return array_map(
            static fn (FailureReason $reason): FailureReasonView => FailureReasonView::fromAggregate($reason),
            $this->reasons->all(),
        );
    }
}
