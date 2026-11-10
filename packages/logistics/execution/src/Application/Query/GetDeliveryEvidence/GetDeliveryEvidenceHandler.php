<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDeliveryEvidence;

use Logistics\Execution\Application\Contract\DeliveryEvidenceReader;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetDeliveryEvidence, list<DeliveryEvidenceView>>
 */
final class GetDeliveryEvidenceHandler implements QueryHandler
{
    public function __construct(
        private readonly DeliveryEvidenceReader $reader,
    ) {}

    /**
     * @return list<DeliveryEvidenceView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof GetDeliveryEvidence) {
            throw ExecutionException::because('GetDeliveryEvidenceHandler solo maneja GetDeliveryEvidence');
        }

        return $this->reader->forShipment($query->shipmentId);
    }
}
