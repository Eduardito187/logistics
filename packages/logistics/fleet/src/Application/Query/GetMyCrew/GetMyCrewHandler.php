<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetMyCrew;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la asignación del día de un chofer y la devuelve como proyección de lectura.
 *
 * Busca la tripulación armada para ese chofer en la fecha; si no hay, devuelve null (el controller lo
 * traduce a 404). Si la hay, enriquece el vehículo, el chofer y cada ayudante con sus datos legibles
 * (placa, nombre) resolviéndolos por id contra sus repositorios.
 *
 * @implements QueryHandler<GetMyCrew, MyCrewView|null>
 */
final readonly class GetMyCrewHandler implements QueryHandler
{
    public function __construct(
        private CrewRepository $crews,
        private VehicleRepository $vehicles,
        private DriverRepository $drivers,
        private HelperRepository $helpers,
    ) {}

    public function handle(Query $query): ?MyCrewView
    {
        assert($query instanceof GetMyCrew);

        $crew = $this->crews->findForDriverOn(
            DriverId::fromString($query->driverId),
            new DateTimeImmutable($query->date),
        );

        if ($crew === null) {
            return null;
        }

        $vehicle = $this->vehicles->findById($crew->vehicleId());
        $driver = $this->drivers->findById($crew->driverId());

        $helpersById = [];
        foreach ($crew->helperIds() as $helperId) {
            $helper = $this->helpers->findById(HelperId::fromString($helperId));
            if ($helper instanceof Helper) {
                $helpersById[$helperId] = $helper;
            }
        }

        return MyCrewView::fromDomain($crew, $vehicle, $driver, $helpersById);
    }
}
