<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\ExpenseMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\ExpenseRecord;

/**
 * Implementación Eloquent del repositorio de gastos de flota. `save` es un upsert por id.
 */
final readonly class EloquentExpenseRepository implements ExpenseRepository
{
    public function __construct(
        private ExpenseMapper $mapper = new ExpenseMapper,
    ) {}

    public function nextIdentity(): ExpenseId
    {
        return ExpenseId::generate();
    }

    public function save(Expense $expense): void
    {
        $row = $this->mapper->toRow($expense);
        ExpenseRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(ExpenseId $id): ?Expense
    {
        $record = ExpenseRecord::query()->find($id->toString());

        return $record instanceof ExpenseRecord ? $this->mapper->toDomain($record) : null;
    }

    /**
     * @return list<Expense>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?ExpenseCategory $category = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Orden determinista para la paginación: incurred_at desc con desempate por id (UUID v7).
        $query = $this->filtered($vehicleId, $category, $from, $to)
            ->orderByDesc('incurred_at')
            ->orderByDesc('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query
            ->get()
            ->map(fn (ExpenseRecord $record): Expense => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }

    public function countBy(
        ?string $vehicleId = null,
        ?ExpenseCategory $category = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int {
        return $this->filtered($vehicleId, $category, $from, $to)->count();
    }

    /**
     * @return array{cents: int, currency: string}
     */
    public function totalFor(
        string $vehicleId,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array {
        $query = $this->filtered($vehicleId, null, $from, $to);

        $cents = (int) $query->sum('amount_cents');

        // La moneda se toma de la primera fila del vehículo; si no hay gastos, se usa la de por defecto.
        $currency = ExpenseRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->value('currency');

        return [
            'cents' => $cents,
            'currency' => is_string($currency) ? $currency : Money::DEFAULT_CURRENCY,
        ];
    }

    /**
     * Arma la consulta base con los filtros opcionales compartidos por listBy y totalFor.
     *
     * @return Builder<ExpenseRecord>
     */
    private function filtered(
        ?string $vehicleId,
        ?ExpenseCategory $category,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): Builder {
        $query = ExpenseRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($category !== null) {
            $query->where('category', $category->value);
        }
        if ($from !== null) {
            $query->where('incurred_at', '>=', $from->format('Y-m-d H:i:s'));
        }
        if ($to !== null) {
            $query->where('incurred_at', '<=', $to->format('Y-m-d H:i:s'));
        }

        return $query;
    }
}
