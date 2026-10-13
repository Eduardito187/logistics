<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Report\ImportCoverageReport;

it('accumulates created, updated and errors', function (): void {
    $report = new ImportCoverageReport;
    $report->recordCreated('Centro');
    $report->recordCreated('Norte');
    $report->recordUpdated('Sur');
    $report->recordError('Feo', 'geometría inválida');

    expect($report->createdCount())->toBe(2)
        ->and($report->updatedCount())->toBe(1)
        ->and($report->errorCount())->toBe(1)
        ->and($report->hasErrors())->toBeTrue()
        ->and($report->created())->toBe(['Centro', 'Norte'])
        ->and($report->updated())->toBe(['Sur'])
        ->and($report->errors())->toBe([['item' => 'Feo', 'reason' => 'geometría inválida']]);
});

it('resets to an empty report', function (): void {
    $report = new ImportCoverageReport;
    $report->recordCreated('Centro');
    $report->recordError('Feo', 'x');

    $report->reset();

    expect($report->createdCount())->toBe(0)
        ->and($report->updatedCount())->toBe(0)
        ->and($report->errorCount())->toBe(0)
        ->and($report->hasErrors())->toBeFalse();
});

it('exposes a primitive array form', function (): void {
    $report = new ImportCoverageReport;
    $report->recordCreated('Centro');
    $report->recordUpdated('Sur');

    expect($report->toArray())->toBe([
        'created' => ['Centro'],
        'updated' => ['Sur'],
        'errors' => [],
        'createdCount' => 1,
        'updatedCount' => 1,
        'errorCount' => 0,
    ]);
});
