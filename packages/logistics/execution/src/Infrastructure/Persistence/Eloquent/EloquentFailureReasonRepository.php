<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Domain\ValueObject\FailureReasonId;

/**
 * Implementación Eloquent del repositorio del catálogo de motivos de entrega fallida.
 *
 * Upsert por `code` (clave natural estable) para tolerar reintentos; el UUID queda estable una vez creado.
 */
final class EloquentFailureReasonRepository implements FailureReasonRepository
{
    public function __construct(
        private readonly FailureReasonMapper $mapper = new FailureReasonMapper(),
    ) {}

    public function nextIdentity(): FailureReasonId
    {
        return FailureReasonId::generate();
    }

    public function save(FailureReason $reason): void
    {
        $row = $this->mapper->toRow($reason);

        FailureReasonRecord::query()->updateOrCreate(
            ['code' => $reason->code()->value],
            $row,
        );
    }

    public function findByCode(FailureReasonCode $code): ?FailureReason
    {
        $record = FailureReasonRecord::query()->where('code', $code->value)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function all(): array
    {
        return $this->mapRecords(
            FailureReasonRecord::query()->orderBy('sort_order')->orderBy('code')->get()->all(),
        );
    }

    public function allActiveOrdered(): array
    {
        return $this->mapRecords(
            FailureReasonRecord::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->all(),
        );
    }

    public function existsByCode(FailureReasonCode $code): bool
    {
        return FailureReasonRecord::query()->where('code', $code->value)->exists();
    }

    /**
     * @param  array<int, FailureReasonRecord> $records
     * @return list<FailureReason>
     */
    private function mapRecords(array $records): array
    {
        return array_values(array_map(
            fn (FailureReasonRecord $record): FailureReason => $this->mapper->toDomain($record),
            $records,
        ));
    }
}
