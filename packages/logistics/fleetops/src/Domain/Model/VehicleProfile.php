<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use Logistics\FleetOps\Domain\Event\VehicleOdometerUpdated;
use Logistics\FleetOps\Domain\Event\VehicleProfileDefined;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Perfil técnico de un vehículo de la flota propia: marca, modelo, año, combustible, tanque, VIN, color
 * y odómetro vigente. Es 1:1 con el vehículo de `fleet` (se referencia por `vehicleId`, un UUID string,
 * sin FK dura). No re-dueña placas ni documentos (viven en `fleet`): solo el detalle operativo/costos.
 *
 * El odómetro vigente solo avanza: es la última lectura conocida, insumo del consumo y de los planes de
 * mantenimiento por km.
 */
final class VehicleProfile extends AggregateRoot
{
    private function __construct(
        private readonly VehicleProfileId $id,
        private readonly string $vehicleId,
        private ?string $brand,
        private ?string $model,
        private ?int $year,
        private ?FuelType $fuelType,
        private ?float $tankLiters,
        private ?string $vin,
        private ?string $color,
        private ?Odometer $currentOdometer,
    ) {}

    /**
     * Define el perfil técnico de un vehículo. Arranca sin odómetro vigente (se fija con updateOdometer).
     */
    public static function define(
        VehicleProfileId $id,
        string $vehicleId,
        ?string $brand,
        ?string $model,
        ?int $year,
        ?FuelType $fuelType,
        ?float $tankLiters,
        ?string $vin,
        ?string $color,
    ): self {
        $vehicleId = trim($vehicleId);
        if ($vehicleId === '') {
            throw FleetOpsException::because('El vehículo del perfil no puede estar vacío.');
        }
        if ($tankLiters !== null && $tankLiters <= 0) {
            throw FleetOpsException::because('La capacidad del tanque debe ser positiva.');
        }

        $profile = new self($id, $vehicleId, $brand, $model, $year, $fuelType, $tankLiters, $vin, $color, null);
        $profile->recordEvent(new VehicleProfileDefined(
            $id->toString(),
            $vehicleId,
            $brand,
            $model,
            $year,
            $fuelType?->value,
        ));

        return $profile;
    }

    /**
     * Reconstituye el perfil desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        VehicleProfileId $id,
        string $vehicleId,
        ?string $brand,
        ?string $model,
        ?int $year,
        ?FuelType $fuelType,
        ?float $tankLiters,
        ?string $vin,
        ?string $color,
        ?Odometer $currentOdometer,
    ): self {
        return new self($id, $vehicleId, $brand, $model, $year, $fuelType, $tankLiters, $vin, $color, $currentOdometer);
    }

    /**
     * Fija el odómetro vigente. Solo avanza: una lectura menor a la actual es un retroceso y se rechaza;
     * una lectura igual no cambia nada ni graba evento (idempotente).
     */
    public function updateOdometer(Odometer $odometer): void
    {
        if ($this->currentOdometer !== null) {
            if ($odometer->km < $this->currentOdometer->km) {
                throw FleetOpsException::because('El odómetro no puede retroceder.');
            }
            if ($odometer->km === $this->currentOdometer->km) {
                return;
            }
        }

        $this->currentOdometer = $odometer;
        $this->recordEvent(new VehicleOdometerUpdated(
            $this->id->toString(),
            $this->vehicleId,
            $odometer->km,
        ));
    }

    /**
     * Actualiza los datos técnicos del vehículo (upsert del perfil). No toca el odómetro ni graba evento:
     * es un ajuste de ficha, no un hecho de operación.
     */
    public function updateSpecs(
        ?string $brand,
        ?string $model,
        ?int $year,
        ?FuelType $fuelType,
        ?float $tankLiters,
        ?string $vin,
        ?string $color,
    ): void {
        if ($tankLiters !== null && $tankLiters <= 0) {
            throw FleetOpsException::because('La capacidad del tanque debe ser positiva.');
        }

        $this->brand = $brand;
        $this->model = $model;
        $this->year = $year;
        $this->fuelType = $fuelType;
        $this->tankLiters = $tankLiters;
        $this->vin = $vin;
        $this->color = $color;
    }

    public function id(): VehicleProfileId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function year(): ?int
    {
        return $this->year;
    }

    public function fuelType(): ?FuelType
    {
        return $this->fuelType;
    }

    public function tankLiters(): ?float
    {
        return $this->tankLiters;
    }

    public function vin(): ?string
    {
        return $this->vin;
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function currentOdometer(): ?Odometer
    {
        return $this->currentOdometer;
    }
}
