<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleUtilization;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\UtilizationView;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Calcula la utilización de un vehículo en el rango: número de salidas, km recorridos sumando los de cada
 * salida cerrada, horas en ruta (desde la salida hasta el retorno de cada salida cerrada) y la última
 * lectura de odómetro del vehículo.
 *
 * @implements QueryHandler<GetVehicleUtilization, UtilizationView>
 */
final readonly class GetVehicleUtilizationHandler implements QueryHandler
{
    public function __construct(
        private DepartureRepository $departures,
        private OdometerRepository $readings,
    ) {}

    public function handle(Query $query): UtilizationView
    {
        assert($query instanceof GetVehicleUtilization);

        $from = new DateTimeImmutable($query->from);
        $to = new DateTimeImmutable($query->to);

        $departures = $this->departures->listBy($query->vehicleId, null, $from, $to);

        $kmTraveled = 0;
        $seconds = 0;
        foreach ($departures as $departure) {
            $km = $departure->kmTraveled();
            if ($km !== null) {
                $kmTraveled += $km;
            }

            $returnedAt = $departure->returnedAt();
            if ($departure->status() === DepartureStatus::Returned && $returnedAt !== null) {
                $seconds += max(0, $returnedAt->getTimestamp() - $departure->departedAt()->getTimestamp());
            }
        }

        $latest = $this->readings->latestForVehicle($query->vehicleId);

        return new UtilizationView(
            $query->vehicleId,
            $from->format(DateTimeImmutable::ATOM),
            $to->format(DateTimeImmutable::ATOM),
            count($departures),
            $kmTraveled,
            round($seconds / 3600, 2),
            $latest?->odometer()->km,
            $latest?->readAt()->format(DateTimeImmutable::ATOM),
        );
    }
}
