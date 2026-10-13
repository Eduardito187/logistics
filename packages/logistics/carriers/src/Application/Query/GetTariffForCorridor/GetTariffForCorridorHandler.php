<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetTariffForCorridor;

use Logistics\Carriers\Application\ReadModel\TariffView;
use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la tarifa de un transportista para un corredor y clase de vehículo, o null si no existe.
 *
 * @implements QueryHandler<GetTariffForCorridor, TariffView|null>
 */
final readonly class GetTariffForCorridorHandler implements QueryHandler
{
    public function __construct(
        private TariffRepository $tariffs,
    ) {}

    public function handle(Query $query): ?TariffView
    {
        assert($query instanceof GetTariffForCorridor);

        $tariff = $this->tariffs->findTariff(
            $query->carrierId,
            CorridorRef::of($query->corridorFrom, $query->corridorTo, $query->corridorType),
            VehicleClass::from($query->vehicleClass),
        );

        return $tariff !== null ? TariffView::fromDomain($tariff) : null;
    }
}
