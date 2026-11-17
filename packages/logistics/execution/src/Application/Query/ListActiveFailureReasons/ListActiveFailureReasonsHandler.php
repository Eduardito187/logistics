<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListActiveFailureReasons;

use Logistics\Execution\Application\ReadModel\FailureReasonView;
use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListActiveFailureReasons, list<FailureReasonView>>
 */
final class ListActiveFailureReasonsHandler implements QueryHandler
{
    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    /**
     * @return list<FailureReasonView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListActiveFailureReasons) {
            throw ExecutionException::because('ListActiveFailureReasonsHandler solo maneja ListActiveFailureReasons');
        }

        return array_map(
            static fn (FailureReason $reason): FailureReasonView => FailureReasonView::fromAggregate($reason),
            $this->reasons->allActiveOrdered(),
        );
    }
}
