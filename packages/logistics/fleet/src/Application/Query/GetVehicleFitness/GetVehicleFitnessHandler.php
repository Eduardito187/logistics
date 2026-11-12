<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetVehicleFitness;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\VehicleFitnessView;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la aptitud del vehículo en la fecha, o null si el vehículo no está registrado en flota.
 *
 * @implements QueryHandler<GetVehicleFitness, VehicleFitnessView|null>
 */
final readonly class GetVehicleFitnessHandler implements QueryHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
    ) {}

    public function handle(Query $query): ?VehicleFitnessView
    {
        assert($query instanceof GetVehicleFitness);

        $vehicle = $this->vehicles->findById(VehicleId::fromString($query->vehicleId));

        return $vehicle !== null
            ? VehicleFitnessView::fromDomain($vehicle, new DateTimeImmutable($query->date))
            : null;
    }
}
