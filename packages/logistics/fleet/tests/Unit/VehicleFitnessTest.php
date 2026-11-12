<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\VehicleCleared;
use Logistics\Fleet\Domain\Event\VehicleMarkedUnfit;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\Model\ChecklistTemplate;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateId;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateItem;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

/**
 * Dominio puro de la auditoría #27: evaluación del checklist contra la plantilla y aptitud del vehículo.
 */
function plantillaUnit(): ChecklistTemplate
{
    return ChecklistTemplate::define(ChecklistTemplateId::generate(), 'Default', null, [
        ChecklistTemplateItem::of('brakes', 'Frenos', 'safety', ChecklistItemCriticality::Critical),
        ChecklistTemplateItem::of('engine', 'Motor', 'mechanical', ChecklistItemCriticality::Critical),
        ChecklistTemplateItem::of('tires', 'Llantas', 'tires', ChecklistItemCriticality::Major),
        ChecklistTemplateItem::of('fuel', 'Combustible', 'fluids', ChecklistItemCriticality::Minor),
    ]);
}

function vehiculoUnit(): Vehicle
{
    $vehicle = Vehicle::register(VehicleId::generate(), PlateNumber::of('ABC123'), Capacity::of(3500, 18.0), 'node-1');
    $vehicle->releaseEvents();

    return $vehicle;
}

it('un crítico fallido deja el checklist no apto; mayor/menor solo se reportan', function (): void {
    $evaluation = plantillaUnit()->evaluate(PreTripChecklist::of([
        'BRAKES ' => false, // clave normalizada
        'engine' => true,
        'tires' => false,
        'fuel' => false,
    ]));

    expect($evaluation->fitToDispatch)->toBeFalse()
        ->and($evaluation->failedCritical)->toBe(['brakes'])
        ->and($evaluation->failedMajor)->toBe(['tires'])
        ->and($evaluation->failedMinor)->toBe(['fuel'])
        ->and($evaluation->missingCritical)->toBe([]);
});

it('un crítico faltante se informa pero no bloquea', function (): void {
    $evaluation = plantillaUnit()->evaluate(PreTripChecklist::of(['brakes' => true]));

    expect($evaluation->fitToDispatch)->toBeTrue()
        ->and($evaluation->missingCritical)->toBe(['engine']);
});

it('marcar no apto bloquea desde esa fecha en adelante y es idempotente', function (): void {
    $vehicle = vehiculoUnit();
    $vehicle->markUnfit(new DateTimeImmutable('2026-11-12'), 'Frenos', 'insp-1', ['brakes']);
    $vehicle->markUnfit(new DateTimeImmutable('2026-11-13'), 'Motor', 'insp-2', ['engine']);

    $events = $vehicle->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleMarkedUnfit::class)
        ->and($events[0]->eventName())->toBe('fleet.vehicle_marked_unfit')
        ->and($vehicle->fitness()->isFitOn(new DateTimeImmutable('2026-11-11')))->toBeTrue()
        ->and($vehicle->fitness()->isFitOn(new DateTimeImmutable('2026-11-12')))->toBeFalse()
        ->and($vehicle->fitness()->isFitOn(new DateTimeImmutable('2026-11-20')))->toBeFalse();

    expect(fn () => $vehicle->ensureFitToDispatchOn(new DateTimeImmutable('2026-11-12')))
        ->toThrow(FleetException::class);
});

it('habilitar exige nota, solo aplica a un vehículo no apto y graba el evento', function (): void {
    $vehicle = vehiculoUnit();

    expect(fn () => $vehicle->clearFitness('1', 'Admin', 'nada'))->toThrow(FleetException::class);

    $vehicle->markUnfit(new DateTimeImmutable('2026-11-12'), 'Frenos', null, ['brakes']);
    $vehicle->releaseEvents();

    expect(fn () => $vehicle->clearFitness('1', 'Admin', '   '))->toThrow(FleetException::class);

    $vehicle->clearFitness('1', 'Admin', 'Pastillas nuevas');
    $events = $vehicle->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleCleared::class)
        ->and($events[0]->eventName())->toBe('fleet.vehicle_cleared')
        ->and($events[0]->payload()['cleared_by'])->toBe('Admin')
        ->and($vehicle->fitness()->isUnfit())->toBeFalse();

    $vehicle->ensureFitToDispatchOn(new DateTimeImmutable('2026-11-12'));
});
