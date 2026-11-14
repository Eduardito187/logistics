<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceActivated;
use Logistics\FleetOps\Domain\Event\FuelPriceDeactivated;
use Logistics\FleetOps\Domain\Event\FuelPriceUpdated;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;

/**
 * Cubre las mutaciones del agregado FuelPrice que no ejercita el test base: edición, cambio de tipo,
 * activación/desactivación idempotentes y la reconstitución desde persistencia.
 */
function precioReconstituido(bool $active = true): FuelPrice
{
    return FuelPrice::reconstitute(
        FuelPriceId::generate(),
        FuelType::Diesel,
        Money::of(372),
        new DateTimeImmutable('2026-10-01'),
        'ANH',
        $active,
    );
}

it('reconstituye un precio sin grabar eventos', function (): void {
    $price = precioReconstituido();

    expect($price->isActive())->toBeTrue()
        ->and($price->fuelType())->toBe(FuelType::Diesel)
        ->and($price->pricePerLiter()->cents)->toBe(372)
        ->and($price->releaseEvents())->toBe([]);
});

it('edita precio, vigencia y fuente, y graba el evento de edición', function (): void {
    $price = precioReconstituido();

    $price->update(Money::of(410), new DateTimeImmutable('2026-11-01'), '  manual  ');

    expect($price->pricePerLiter()->cents)->toBe(410)
        ->and($price->effectiveFrom()->format('Y-m-d'))->toBe('2026-11-01')
        ->and($price->source())->toBe('manual');

    $events = $price->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FuelPriceUpdated::class);
});

it('cambia el tipo de combustible y graba la edición; ignora el cambio al mismo tipo', function (): void {
    $price = precioReconstituido();

    $price->changeFuelType(FuelType::GasolineSpecial);
    expect($price->fuelType())->toBe(FuelType::GasolineSpecial);
    $events = $price->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FuelPriceUpdated::class);

    // Cambiar al mismo tipo no hace nada ni graba evento.
    $price->changeFuelType(FuelType::GasolineSpecial);
    expect($price->releaseEvents())->toBe([]);
});

it('activa un precio inactivo y es idempotente', function (): void {
    $price = precioReconstituido(active: false);

    $price->activate();
    expect($price->isActive())->toBeTrue();
    $events = $price->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FuelPriceActivated::class);

    // Ya activo: sin evento.
    $price->activate();
    expect($price->releaseEvents())->toBe([]);
});

it('desactiva un precio activo y es idempotente', function (): void {
    $price = precioReconstituido(active: true);

    $price->deactivate();
    expect($price->isActive())->toBeFalse();
    $events = $price->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FuelPriceDeactivated::class);

    // Ya inactivo: sin evento.
    $price->deactivate();
    expect($price->releaseEvents())->toBe([]);
});
