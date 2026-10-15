<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Model;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Event\FuelPriceActivated;
use Logistics\FleetOps\Domain\Event\FuelPriceDeactivated;
use Logistics\FleetOps\Domain\Event\FuelPriceSet;
use Logistics\FleetOps\Domain\Event\FuelPriceUpdated;
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
 *
 * Un precio puede editarse, activarse/desactivarse (sin borrarlo del histórico) y eliminarse desde el
 * admin. Las propiedades mutables no son `readonly`; la identidad sí.
 */
final class FuelPrice extends AggregateRoot
{
    private function __construct(
        private readonly FuelPriceId $id,
        private FuelType $fuelType,
        private Money $pricePerLiter,
        private DateTimeImmutable $effectiveFrom,
        private ?string $source,
        private bool $active,
    ) {}

    /**
     * Fija un precio nuevo para un tipo de combustible y graba el evento correspondiente. Arranca activo.
     */
    public static function set(
        FuelPriceId $id,
        FuelType $fuelType,
        Money $pricePerLiter,
        DateTimeImmutable $effectiveFrom,
        ?string $source,
    ): self {
        $source = self::normalizeSource($source);

        $price = new self($id, $fuelType, $pricePerLiter, $effectiveFrom, $source, true);
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
        bool $active,
    ): self {
        return new self($id, $fuelType, $pricePerLiter, $effectiveFrom, $source, $active);
    }

    /**
     * Edita el precio (incluida la moneda, que viaja en el Money), la vigencia y la fuente.
     */
    public function update(Money $price, DateTimeImmutable $effectiveFrom, ?string $source): void
    {
        $this->pricePerLiter = $price;
        $this->effectiveFrom = $effectiveFrom;
        $this->source = self::normalizeSource($source);

        $this->recordEvent(new FuelPriceUpdated(
            $this->id->toString(),
            $this->fuelType->value,
            $this->pricePerLiter->cents,
            $this->pricePerLiter->currency,
            $this->effectiveFrom->format('Y-m-d'),
            $this->source,
        ));
    }

    /**
     * Cambia el tipo de combustible al que aplica este precio.
     */
    public function changeFuelType(FuelType $fuelType): void
    {
        if ($this->fuelType === $fuelType) {
            return;
        }

        $this->fuelType = $fuelType;

        $this->recordEvent(new FuelPriceUpdated(
            $this->id->toString(),
            $this->fuelType->value,
            $this->pricePerLiter->cents,
            $this->pricePerLiter->currency,
            $this->effectiveFrom->format('Y-m-d'),
            $this->source,
        ));
    }

    /**
     * Activa el precio. Idempotente: si ya está activo no hace nada.
     */
    public function activate(): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->recordEvent(new FuelPriceActivated($this->id->toString()));
    }

    /**
     * Desactiva el precio. Idempotente: si ya está inactivo no hace nada.
     */
    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->recordEvent(new FuelPriceDeactivated($this->id->toString()));
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

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Normaliza la fuente: trim y, si queda vacía, null.
     */
    private static function normalizeSource(?string $source): ?string
    {
        return $source !== null ? (trim($source) ?: null) : null;
    }
}
