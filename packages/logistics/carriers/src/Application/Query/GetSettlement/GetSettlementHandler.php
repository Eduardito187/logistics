<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetSettlement;

use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una liquidación por su id, o null si no existe.
 *
 * @implements QueryHandler<GetSettlement, SettlementView|null>
 */
final readonly class GetSettlementHandler implements QueryHandler
{
    public function __construct(
        private SettlementRepository $settlements,
    ) {}

    public function handle(Query $query): ?SettlementView
    {
        assert($query instanceof GetSettlement);

        $settlement = $this->settlements->findById(SettlementId::fromString($query->settlementId));

        if ($settlement === null) {
            return null;
        }

        // Nombre legible del transportista (lado lectura), junto al UUID crudo de carrier_id.
        $carrierName = CarrierRecord::query()->whereKey($settlement->carrierId())->value('name');

        return SettlementView::fromDomain($settlement, is_string($carrierName) ? $carrierName : null);
    }
}
