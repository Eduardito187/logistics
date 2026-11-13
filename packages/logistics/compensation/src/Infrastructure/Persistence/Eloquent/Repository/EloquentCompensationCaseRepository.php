<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Compensation\Domain\Contract\CompensationCaseRepository;
use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\ValueObject\CaseStatus;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Domain\ValueObject\Money;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Mapper\CompensationCaseMapper;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\CompensationCaseRecord;

/**
 * Implementación Eloquent del repositorio de casos. `save` es un upsert por id.
 */
final readonly class EloquentCompensationCaseRepository implements CompensationCaseRepository
{
    public function __construct(
        private CompensationCaseMapper $mapper = new CompensationCaseMapper,
    ) {}

    public function nextIdentity(): CompensationCaseId
    {
        return CompensationCaseId::generate();
    }

    public function save(CompensationCase $case): void
    {
        $row = $this->mapper->toRow($case);
        CompensationCaseRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(CompensationCaseId $id): ?CompensationCase
    {
        $record = CompensationCaseRecord::query()->find($id->toString());

        return $record instanceof CompensationCaseRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?CompensationCase
    {
        $record = CompensationCaseRecord::query()->where('idempotency_key', $idempotencyKey)->first();

        return $record instanceof CompensationCaseRecord ? $this->mapper->toDomain($record) : null;
    }

    public function sumCommittedForOrder(string $orderReference, string $currency): Money
    {
        // Solo cuentan los casos que comprometen monto: propuesto, aprobado y emitido. Excluye rechazados
        // y no compensables. Es la base del tope acumulado por orden (§2.1.2, regla 3).
        $sum = (int) CompensationCaseRecord::query()
            ->where('order_reference', $orderReference)
            ->whereIn('status', [
                CaseStatus::Proposed->value,
                CaseStatus::Approved->value,
                CaseStatus::Issued->value,
            ])
            ->sum('effective_cents');

        return Money::of($sum, $currency);
    }
}
