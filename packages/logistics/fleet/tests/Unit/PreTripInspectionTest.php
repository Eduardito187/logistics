<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\PreTripChecklistConducted;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

/**
 * @param array<string, bool> $items
 */
function realizarInspeccion(array $items): PreTripInspection
{
    return PreTripInspection::conduct(
        PreTripInspectionId::generate(),
        VehicleId::generate(),
        DriverId::generate(),
        PreTripChecklist::of($items),
        new DateTimeImmutable('2026-10-13'),
    );
}

it('aprueba cuando todos los ítems están verificados y graba el evento', function (): void {
    $inspection = realizarInspeccion(['tires' => true, 'brakes' => true]);

    expect($inspection->passed())->toBeTrue();

    $events = $inspection->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PreTripChecklistConducted::class)
        ->and($events[0]->passed)->toBeTrue();
});

it('no aprueba si queda algún ítem sin verificar', function (): void {
    $inspection = realizarInspeccion(['tires' => true, 'brakes' => false]);

    expect($inspection->passed())->toBeFalse()
        ->and($inspection->checklist()->pending())->toBe(['brakes']);

    $events = $inspection->releaseEvents();
    expect($events[0]->passed)->toBeFalse();
});
