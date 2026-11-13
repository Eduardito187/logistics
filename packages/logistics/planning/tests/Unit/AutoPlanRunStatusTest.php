<?php

declare(strict_types=1);

use Logistics\Planning\Domain\ValueObject\AutoPlanRunStatus;

it('maps each case to its string value', function (): void {
    expect(AutoPlanRunStatus::Queued->value)->toBe('queued')
        ->and(AutoPlanRunStatus::Processing->value)->toBe('processing')
        ->and(AutoPlanRunStatus::Completed->value)->toBe('completed')
        ->and(AutoPlanRunStatus::Failed->value)->toBe('failed');
});

it('builds from its string value', function (): void {
    expect(AutoPlanRunStatus::from('queued'))->toBe(AutoPlanRunStatus::Queued)
        ->and(AutoPlanRunStatus::from('failed'))->toBe(AutoPlanRunStatus::Failed);
});

it('exposes an English label for every case', function (): void {
    expect(AutoPlanRunStatus::Queued->label())->toBe('Queued')
        ->and(AutoPlanRunStatus::Processing->label())->toBe('Processing')
        ->and(AutoPlanRunStatus::Completed->label())->toBe('Completed')
        ->and(AutoPlanRunStatus::Failed->label())->toBe('Failed');
});

it('blocks a new run unless the previous one failed (idempotency)', function (): void {
    expect(AutoPlanRunStatus::Queued->blocksNewRun())->toBeTrue()
        ->and(AutoPlanRunStatus::Processing->blocksNewRun())->toBeTrue()
        ->and(AutoPlanRunStatus::Completed->blocksNewRun())->toBeTrue()
        ->and(AutoPlanRunStatus::Failed->blocksNewRun())->toBeFalse();
});
