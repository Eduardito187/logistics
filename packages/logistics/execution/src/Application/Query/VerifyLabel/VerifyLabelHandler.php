<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\VerifyLabel;

use Logistics\Execution\Application\ReadModel\LabelVerificationView;
use Logistics\Execution\Domain\Contract\LabelVerificationGateway;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<VerifyLabel, LabelVerificationView>
 */
final class VerifyLabelHandler implements QueryHandler
{
    public function __construct(
        private readonly LabelVerificationGateway $gateway,
    ) {}

    public function handle(Query $query): LabelVerificationView
    {
        if (!$query instanceof VerifyLabel) {
            throw ExecutionException::because('VerifyLabelHandler solo maneja VerifyLabel');
        }

        $result = $this->gateway->verify($query->canonicalPayload, $query->signature, $query->keyId);

        return LabelVerificationView::fromResult($result);
    }
}
