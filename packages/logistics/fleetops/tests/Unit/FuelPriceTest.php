<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceSet;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;

it('fija un precio de combustible y graba el evento', function (): void {
    $price = FuelPrice::set(
        FuelPriceId::generate(),
        FuelType::Diesel,
        Money::of(372),
        new DateTimeImmutable('2026-10-01'),
        'ANH',
    );

    expect($price->fuelType())->toBe(FuelType::Diesel)
        ->and($price->pricePerLiter()->cents)->toBe(372)
        ->and($price->source())->toBe('ANH')
        ->and($price->effectiveFrom()->format('Y-m-d'))->toBe('2026-10-01');

    $events = $price->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FuelPriceSet::class);
});

it('normaliza una fuente vacía a nulo', function (): void {
    $price = FuelPrice::set(
        FuelPriceId::generate(),
        FuelType::GasolineSpecial,
        Money::of(479),
        new DateTimeImmutable('2026-10-01'),
        '   ',
    );

    expect($price->source())->toBeNull();
});
