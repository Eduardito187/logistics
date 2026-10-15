<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\RefuelRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Carga de combustible de un vehículo de la flota propia: litros, precio por litro y costo total, con el
 * odómetro al momento de cargar. El vehículo se referencia por su id como texto (`vehicleId` = UUID del
 * vehículo de `fleet`), sin FK dura, para mantener el contexto desacoplable.
 *
 * El costo total se calcula en el dominio (precio/litro × litros) y se guarda en centavos, así el análisis
 * de consumo no recalcula nada. El flag `fullTank` marca las cargas a tanque lleno, que son las que
 * permiten medir el consumo real entre dos llenados consecutivos.
 */
final class Refuel extends AggregateRoot
{
    private function __construct(
        private readonly RefuelId $id,
        private readonly string $vehicleId,
        private readonly ?string $driverId,
        private readonly FuelType $fuelType,
        private readonly float $liters,
        private readonly Money $pricePerLiter,
        private readonly Money $totalCost,
        private readonly ?Odometer $odometer,
        private readonly ?string $station,
        private readonly bool $fullTank,
        private readonly DateTimeImmutable $refueledAt,
        private readonly ?string $note,
    ) {}

    /**
     * Registra una carga: valida los litros, calcula el costo total y graba el evento.
     */
    public static function record(
        RefuelId $id,
        string $vehicleId,
        ?string $driverId,
        FuelType $fuelType,
        float $liters,
        Money $pricePerLiter,
        ?Odometer $odometer,
        ?string $station,
        bool $fullTank,
        DateTimeImmutable $refueledAt,
        ?string $note,
    ): self {
        if ($liters <= 0.0) {
            throw FleetOpsException::because("Los litros de la carga deben ser mayores a cero: {$liters}.");
        }
        $station = $station !== null ? (trim($station) ?: null) : null;
        $note = $note !== null ? (trim($note) ?: null) : null;

        $totalCost = $pricePerLiter->multiply($liters);

        $refuel = new self(
            $id,
            $vehicleId,
            $driverId,
            $fuelType,
            $liters,
            $pricePerLiter,
            $totalCost,
            $odometer,
            $station,
            $fullTank,
            $refueledAt,
            $note,
        );
        $refuel->recordEvent(new RefuelRecorded(
            $id->toString(),
            $vehicleId,
            $fuelType->value,
            $liters,
            $totalCost->cents,
            $totalCost->currency,
            $odometer?->km,
            $refueledAt->format(DateTimeImmutable::ATOM),
        ));

        return $refuel;
    }

    /**
     * Reconstituye una carga desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        RefuelId $id,
        string $vehicleId,
        ?string $driverId,
        FuelType $fuelType,
        float $liters,
        Money $pricePerLiter,
        Money $totalCost,
        ?Odometer $odometer,
        ?string $station,
        bool $fullTank,
        DateTimeImmutable $refueledAt,
        ?string $note,
    ): self {
        return new self(
            $id,
            $vehicleId,
            $driverId,
            $fuelType,
            $liters,
            $pricePerLiter,
            $totalCost,
            $odometer,
            $station,
            $fullTank,
            $refueledAt,
            $note,
        );
    }

    public function id(): RefuelId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function driverId(): ?string
    {
        return $this->driverId;
    }

    public function fuelType(): FuelType
    {
        return $this->fuelType;
    }

    public function liters(): float
    {
        return $this->liters;
    }

    public function pricePerLiter(): Money
    {
        return $this->pricePerLiter;
    }

    public function totalCost(): Money
    {
        return $this->totalCost;
    }

    public function odometer(): ?Odometer
    {
        return $this->odometer;
    }

    public function station(): ?string
    {
        return $this->station;
    }

    public function isFullTank(): bool
    {
        return $this->fullTank;
    }

    public function refueledAt(): DateTimeImmutable
    {
        return $this->refueledAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
