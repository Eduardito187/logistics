<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository;

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

    public function list(?string $carrierId = null, ?string $status = null): array
    {
        $query = SettlementRecord::query()->orderByDesc('period')->orderBy('carrier_id');

        if ($carrierId !== null && $carrierId !== '') {
            $query->where('carrier_id', $carrierId);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return array_values($query
            ->get()
            ->map(fn (SettlementRecord $record): Settlement => $this->mapper->toDomain($record))
            ->all());
    }
}
