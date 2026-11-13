<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Query\GetCompensationCase;

use Logistics\Compensation\Application\ReadModel\CompensationCaseView;
use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetCompensationCase, CompensationCaseView|null>
 */
final readonly class GetCompensationCaseHandler implements QueryHandler
{
    public function __construct(
        private CompensationCaseRepository $cases,
    ) {}

    public function handle(Query $query): ?CompensationCaseView
    {
        assert($query instanceof GetCompensationCase);

        $case = $this->cases->findById(CompensationCaseId::fromString($query->caseId));

        return $case !== null ? CompensationCaseView::fromDomain($case) : null;
    }
}
