<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;

/**
 * Puerto de persistencia de precios de combustible. La implementación vive en Infrastructure.
 */
interface FuelPriceRepository
{
    public function nextIdentity(): FuelPriceId;

    public function save(FuelPrice $price): void;

    /**
     * Precio vigente de un tipo de combustible en una fecha: el más reciente con `effective_from <= at`.
     */
    public function currentFor(FuelType $fuelType, DateTimeImmutable $at): ?FuelPrice;

    /**
     * Todos los precios registrados (histórico), para listados y filtros.
     *
     * @return list<FuelPrice>
     */
    public function all(): array;
}
