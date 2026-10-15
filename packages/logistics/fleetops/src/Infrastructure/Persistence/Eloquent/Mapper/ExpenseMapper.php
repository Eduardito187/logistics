<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\ExpenseRecord;

/**
 * Traduce entre el agregado Expense (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class ExpenseMapper
{
    public function toDomain(ExpenseRecord $record): Expense
    {
        return Expense::reconstitute(
            ExpenseId::fromString($record->id),
            $record->vehicle_id,
            ExpenseCategory::from($record->category),
            Money::of($record->amount_cents, $record->currency),
            $record->description,
            // `incurred_at` está casteado a datetime (Carbon): se pasa a DateTimeImmutable para el dominio.
            $record->incurred_at->toDateTimeImmutable(),
            $record->note,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, category: string, amount_cents: int, currency: string, description: string|null, incurred_at: string, note: string|null}
     */
    public function toRow(Expense $expense): array
    {
        return [
            'id' => $expense->id()->toString(),
            'vehicle_id' => $expense->vehicleId(),
            'category' => $expense->category()->value,
            'amount_cents' => $expense->amount()->cents,
            'currency' => $expense->amount()->currency,
            'description' => $expense->description(),
            'incurred_at' => $expense->incurredAt()->format('Y-m-d H:i:s'),
            'note' => $expense->note(),
        ];
    }
}
