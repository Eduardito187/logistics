<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper\SettlementMapper;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;

/**
 * Implementación Eloquent del repositorio de liquidaciones. `save` es un upsert por id.
 */
final readonly class EloquentSettlementRepository implements SettlementRepository
{
    public function __construct(
        private SettlementMapper $mapper = new SettlementMapper,
    ) {}

    public function nextIdentity(): SettlementId
    {
        return SettlementId::generate();
    }

    public function save(Settlement $settlement): void
    {
        $row = $this->mapper->toRow($settlement);
        SettlementRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(SettlementId $id): ?Settlement
    {
        $record = SettlementRecord::query()->find($id->toString());

        return $record instanceof SettlementRecord ? $this->mapper->toDomain($record) : null;
    }

    public function list(
        ?string $carrierId = null,
        ?string $status = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): array {
        // Desempate por id (UUID v7) para que la paginación sea determinista ante periodos repetidos.
        $query = $this->filtered($carrierId, $status, $periodFrom, $periodTo)
            ->orderByDesc('period')->orderBy('carrier_id')->orderBy('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (SettlementRecord $record): Settlement => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(
        ?string $carrierId = null,
        ?string $status = null,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): int {
        return $this->filtered($carrierId, $status, $periodFrom, $periodTo)->count();
    }

    /**
     * Query builder con los filtros comunes de list/countBy (sin orden ni recorte). Los períodos son meses
     * `YYYY-MM` inclusivos.
     *
     * @return Builder<SettlementRecord>
     */
    private function filtered(?string $carrierId, ?string $status, ?string $periodFrom = null, ?string $periodTo = null): Builder
    {
        $query = SettlementRecord::query();

        if ($carrierId !== null && $carrierId !== '') {
            $query->where('carrier_id', $carrierId);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        // `period` es texto `YYYY-MM`: la comparación lexicográfica equivale a la cronológica y usa los
        // índices (carrier_id, period) y (period, carrier_id).
        if ($periodFrom !== null && $periodFrom !== '') {
            $query->where('period', '>=', $periodFrom);
        }
        if ($periodTo !== null && $periodTo !== '') {
            $query->where('period', '<=', $periodTo);
        }

        return $query;
    }
}
