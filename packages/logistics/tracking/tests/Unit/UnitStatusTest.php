<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\ValueObject\UnitStatus;

it('maps per-parcel execution events to the unit status', function (): void {
    expect(UnitStatus::fromExecutionEvent('execution.shipment_prepared'))->toBe(UnitStatus::Prepared)
        ->and(UnitStatus::fromExecutionEvent('execution.shipment_loaded'))->toBe(UnitStatus::Loaded)
        ->and(UnitStatus::fromExecutionEvent('execution.shipment_unloaded'))->toBe(UnitStatus::Unloaded)
        ->and(UnitStatus::fromExecutionEvent('execution.node_reception_recorded'))->toBe(UnitStatus::Received)
        ->and(UnitStatus::fromExecutionEvent('execution.shipment_delivered'))->toBe(UnitStatus::Delivered)
        ->and(UnitStatus::fromExecutionEvent('execution.delivery_failed'))->toBe(UnitStatus::Failed);
});

it('returns null for events that do not affect the parcel', function (): void {
    expect(UnitStatus::fromExecutionEvent('execution.vehicle_incident_reported'))->toBeNull()
        ->and(UnitStatus::fromExecutionEvent('something.else'))->toBeNull();
});

it('flags only Delivered and Failed as terminal', function (): void {
    expect(UnitStatus::Delivered->isTerminal())->toBeTrue()
        ->and(UnitStatus::Failed->isTerminal())->toBeTrue()
        ->and(UnitStatus::Loaded->isTerminal())->toBeFalse()
        ->and(UnitStatus::Received->isTerminal())->toBeFalse()
        ->and(UnitStatus::Registered->isTerminal())->toBeFalse();
});
