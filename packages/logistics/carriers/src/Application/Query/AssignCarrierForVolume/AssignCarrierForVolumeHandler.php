<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\AssignCarrierForVolume;

use Logistics\Carriers\Application\ReadModel\AssignmentView;
use Logistics\Carriers\Application\ReadModel\TariffView;
use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Domain\Service\CorridorAssignmentPolicy;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la asignación por volumen: la política de dominio elige la clase de vehículo y el repositorio
 * de tarifas devuelve la más barata para esa clase en el corredor.
 *
 * @implements QueryHandler<AssignCarrierForVolume, AssignmentView>
 */
final readonly class AssignCarrierForVolumeHandler implements QueryHandler
{
    public function __construct(
        private TariffRepository $tariffs,
        private CorridorAssignmentPolicy $policy = new CorridorAssignmentPolicy,
    ) {}

    public function handle(Query $query): AssignmentView
    {
        assert($query instanceof AssignCarrierForVolume);

        $vehicleClass = $this->policy->classForVolume($query->volumeM3);
        $corridor = CorridorRef::of($query->corridorFrom, $query->corridorTo, $query->corridorType);

        $tariff = $this->tariffs->cheapestForCorridorAndClass($corridor, $vehicleClass);

        $tariffView = null;
        if ($tariff !== null) {
            // El tarifario embebido también lleva el nombre legible del transportista.
            $carrierName = CarrierRecord::query()->whereKey($tariff->carrierId())->value('name');
            $tariffView = TariffView::fromDomain($tariff, is_string($carrierName) ? $carrierName : null);
        }

        return new AssignmentView(
            $query->volumeM3,
            $vehicleClass->value,
            $tariffView,
        );
    }
}
