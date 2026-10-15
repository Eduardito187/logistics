<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetFuelConsumption;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\FuelConsumptionView;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Calcula el consumo de un vehículo en un rango por el método "tanque lleno a tanque lleno": toma las
 * cargas a tanque lleno con odómetro, mide los km entre la primera y la última, y divide por los litros
 * cargados entre ambas (excluyendo el primer llenado, que solo fija el punto de partida).
 *
 * Así el rendimiento (km/l) y el costo por km reflejan el combustible realmente quemado en ese tramo.
 *
 * @implements QueryHandler<GetFuelConsumption, FuelConsumptionView>
 */
final readonly class GetFuelConsumptionHandler implements QueryHandler
{
    public function __construct(
        private RefuelRepository $refuels,
    ) {}

    public function handle(Query $query): FuelConsumptionView
    {
        assert($query instanceof GetFuelConsumption);

        $from = new DateTimeImmutable($query->from);
        $to = new DateTimeImmutable($query->to);

        // Cargas del vehículo ordenadas por fecha ascendente, recortadas al rango pedido.
        $ordered = array_values(array_filter(
            $this->refuels->forVehicleOrdered($query->vehicleId),
            static fn (Refuel $refuel): bool => $refuel->refueledAt() >= $from && $refuel->refueledAt() <= $to,
        ));

        $totalLiters = 0.0;
        $totalCost = Money::zero();
        foreach ($ordered as $refuel) {
            $totalLiters += $refuel->liters();
            $totalCost = $totalCost->add($refuel->totalCost());
        }

        // Posiciones de la primera y última carga a tanque lleno con odómetro: sin dos no se mide consumo.
        $firstFull = null;
        $lastFull = null;
        foreach ($ordered as $position => $refuel) {
            if ($refuel->isFullTank() && $refuel->odometer() !== null) {
                $firstFull ??= $position;
                $lastFull = $position;
            }
        }

        $kmTraveled = 0;
        $kmPerLiter = null;
        $costPerKmCents = null;

        if ($firstFull !== null && $lastFull !== null && $lastFull > $firstFull) {
            $firstOdometer = $ordered[$firstFull]->odometer();
            $lastOdometer = $ordered[$lastFull]->odometer();
            assert($firstOdometer !== null && $lastOdometer !== null);

            $kmTraveled = $lastOdometer->km - $firstOdometer->km;

            // Litros y costo cargados después del primer llenado y hasta el último (combustible del tramo).
            $litersConsumed = 0.0;
            $costConsumedCents = 0;
            for ($i = $firstFull + 1; $i <= $lastFull; $i++) {
                $litersConsumed += $ordered[$i]->liters();
                $costConsumedCents += $ordered[$i]->totalCost()->cents;
            }

            if ($kmTraveled > 0 && $litersConsumed > 0.0) {
                $kmPerLiter = round($kmTraveled / $litersConsumed, 2);
                $costPerKmCents = (int) round($costConsumedCents / $kmTraveled);
            }
        }

        return new FuelConsumptionView(
            $query->vehicleId,
            $from->format(DateTimeImmutable::ATOM),
            $to->format(DateTimeImmutable::ATOM),
            round($totalLiters, 2),
            $totalCost->cents,
            $totalCost->currency,
            $kmTraveled,
            $kmPerLiter,
            $costPerKmCents,
        );
    }
}
