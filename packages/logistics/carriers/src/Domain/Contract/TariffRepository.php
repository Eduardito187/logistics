<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Contract;

use Logistics\Carriers\Domain\Model\Tariff;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;

/**
 * Puerto de persistencia de tarifas. La implementación vive en Infrastructure.
 */
interface TariffRepository
{
    public function nextIdentity(): TariffId;

    public function save(Tariff $tariff): void;

    public function findById(TariffId $id): ?Tariff;

    /**
     * Tarifa de un transportista concreto para un corredor y clase de vehículo, si existe.
     */
    public function findTariff(string $carrierId, CorridorRef $corridor, VehicleClass $class): ?Tariff;

    /**
     * Tarifa más barata ofrecida por cualquier transportista para un corredor (consolidación por costo).
     */
    public function cheapestForCorridor(CorridorRef $corridor): ?Tariff;

    /**
     * Tarifa más barata para un corredor y una clase de vehículo concreta (asignación por volumen).
     */
    public function cheapestForCorridorAndClass(CorridorRef $corridor, VehicleClass $class): ?Tariff;
}
