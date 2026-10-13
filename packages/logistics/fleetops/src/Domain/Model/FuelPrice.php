<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\FuelPriceSet;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Precio por litro de un tipo de combustible, vigente desde una fecha. Es un hecho fechado: cada cambio
 * de precio es un registro nuevo (no se mutan los precios viejos), así el histórico queda intacto y el
 * precio vigente a una fecha se resuelve tomando el más reciente con `effective_from <= fecha`.
 *
 * El agregado solo referencia el tipo de combustible y guarda el precio; no decide a qué carga se aplica
 * (eso lo arma la consulta de consumo).
 */
final class FuelPrice extends AggregateRoot
{
    private function __construct(
        private readonly FuelPriceId $id,
        private readonly FuelType $fuelType,
        private readonly Money $pricePerLiter,
        private readonly DateTimeImmutable $effectiveFrom,
        private readonly ?string $source,
    ) {}

    /**
     * Fija un precio nuevo para un tipo de combustible y graba el evento correspondiente.
     */
    public static function set(
        FuelPriceId $id,
        FuelType $fuelType,
        Money $pricePerLiter,
        DateTimeImmutable $effectiveFrom,
        ?string $source,
    ): self {
        $source = $source !== null ? (trim($source) ?: null) : null;

        $price = new self($id, $fuelType, $pricePerLiter, $effectiveFrom, $source);
        $price->recordEvent(new FuelPriceSet(
            $id->toString(),
            $fuelType->value,
            $pricePerLiter->cents,
            $pricePerLiter->currency,
            $effectiveFrom->format('Y-m-d'),
            $source,
        ));

        return $price;
    }

    /**
     * Reconstituye un precio desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        FuelPriceId $id,
        FuelType $fuelType,
        Money $pricePerLiter,
        DateTimeImmutable $effectiveFrom,
        ?string $source,
    ): self {
        return new self($id, $fuelType, $pricePerLiter, $effectiveFrom, $source);
    }

    public function id(): FuelPriceId
    {
        return $this->id;
    }

    public function fuelType(): FuelType
    {
        return $this->fuelType;
    }

    public function pricePerLiter(): Money
    {
        return $this->pricePerLiter;
    }

    public function effectiveFrom(): DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function source(): ?string
    {
        return $this->source;
    }
}
