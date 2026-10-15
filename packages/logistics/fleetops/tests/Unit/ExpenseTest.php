<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\ExpenseRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;
use Logistics\FleetOps\Domain\ValueObject\Money;

it('registra un gasto y graba el evento', function (): void {
    $expense = Expense::record(
        ExpenseId::generate(),
        'vehicle-1',
        ExpenseCategory::Toll,
        Money::of(5_000),
        'Peaje La Paz - Oruro',
        new DateTimeImmutable('2026-10-05T09:00:00+00:00'),
        null,
    );

    expect($expense->category())->toBe(ExpenseCategory::Toll)
        ->and($expense->amount()->cents)->toBe(5_000)
        ->and($expense->description())->toBe('Peaje La Paz - Oruro');

    $events = $expense->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ExpenseRecorded::class);
});

it('rechaza un gasto sin vehículo', function (): void {
    expect(fn () => Expense::record(
        ExpenseId::generate(),
        '  ',
        ExpenseCategory::Fine,
        Money::of(1_000),
        null,
        new DateTimeImmutable('2026-10-05T09:00:00+00:00'),
        null,
    ))->toThrow(FleetOpsException::class, 'no puede estar vacío');
});

it('rechaza un importe no positivo', function (): void {
    expect(fn () => Expense::record(
        ExpenseId::generate(),
        'vehicle-1',
        ExpenseCategory::Parking,
        Money::of(0),
        null,
        new DateTimeImmutable('2026-10-05T09:00:00+00:00'),
        null,
    ))->toThrow(FleetOpsException::class, 'debe ser positivo');
});
