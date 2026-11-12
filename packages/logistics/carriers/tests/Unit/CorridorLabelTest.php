<?php

declare(strict_types=1);

use Logistics\Carriers\Application\Service\CorridorLabel;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\Money;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;

it('humaniza los slugs de corredor con siglas en mayúsculas', function (string $slug, string $label): void {
    expect(CorridorLabel::humanize($slug))->toBe($label);
})->with([
    'cd' => ['cd-santa-cruz', 'CD Santa Cruz'],
    'cd la paz' => ['cd-la-paz', 'CD La Paz'],
    'tienda' => ['store-scz-centro', 'Store SCZ Centro'],
    'guion bajo' => ['cd_cochabamba', 'CD Cochabamba'],
    'mayúsculas' => ['CD-LPZ-01', 'CD LPZ 01'],
    'vacío' => ['', ''],
]);

it('la liquidación conserva la clase de vehículo y es null por defecto', function (): void {
    $build = static fn (?VehicleClass $class): Settlement => Settlement::submit(
        SettlementId::generate(),
        'carrier-1',
        CorridorRef::of('cd-santa-cruz', 'cd-la-paz', 'cd_to_cd'),
        BillingPeriod::of('2026-10'),
        Money::of(100_000, 'BOB'),
        'INV-1',
        [],
        $class,
    );

    expect($build(VehicleClass::Trailer)->vehicleClass())->toBe(VehicleClass::Trailer)
        ->and($build(null)->vehicleClass())->toBeNull();
});
