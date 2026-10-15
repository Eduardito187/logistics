<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\CheapestCarrierForCorridor;

use Logistics\Carriers\Application\ReadModel\TariffView;
use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la tarifa más barata para un corredor, o null si no hay ninguna.
 *
 * @implements QueryHandler<CheapestCarrierForCorridor, TariffView|null>
 */
final readonly class CheapestCarrierForCorridorHandler implements QueryHandler
{
    public function __construct(
        private TariffRepository $tariffs,
    ) {}

    public function handle(Query $query): ?TariffView
    {
        assert($query instanceof CheapestCarrierForCorridor);

        $tariff = $this->tariffs->cheapestForCorridor(
            CorridorRef::of($query->corridorFrom, $query->corridorTo, $query->corridorType),
        );

        if ($tariff === null) {
            return null;
        }

        // Nombre legible del transportista (lado lectura), junto al UUID crudo de carrier_id.
        $carrierName = CarrierRecord::query()->whereKey($tariff->carrierId())->value('name');

        return TariffView::fromDomain($tariff, is_string($carrierName) ? $carrierName : null);
    }
}
