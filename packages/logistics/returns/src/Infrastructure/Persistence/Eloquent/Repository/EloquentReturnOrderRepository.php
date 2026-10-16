<?php

declare(strict_types=1);

namespace Logistics\Returns\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Mapper\ReturnOrderMapper;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de devoluciones. `save` es un upsert por id; las líneas se
 * reemplazan en bloque (idempotente). Cada línea lleva su propio UUID v7 (la devolución es la raíz).
 */
final readonly class EloquentReturnOrderRepository implements ReturnOrderRepository
{
    public function __construct(
        private ReturnOrderMapper $mapper = new ReturnOrderMapper,
    ) {}

    public function nextIdentity(): ReturnId
    {
        return ReturnId::generate();
    }

    public function save(ReturnOrder $returnOrder): void
    {
        $row = $this->mapper->toRow($returnOrder);
        $record = ReturnRecord::query()->updateOrCreate(['id' => $row['id']], $row);

        // Reemplaza las líneas: se borran y se reinsertan con UUID v7 propio.
        $record->lines()->delete();
        foreach ($this->mapper->toLineRows($returnOrder) as $line) {
            $record->lines()->create([
                'id' => Uuid::generate()->value,
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'reason' => $line['reason'],
            ]);
        }
    }

    public function findById(ReturnId $id): ?ReturnOrder
    {
        $record = ReturnRecord::query()->with('lines')->find($id->toString());

        return $record instanceof ReturnRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(?string $ownerPartyId = null, ?string $status = null): array
    {
        $records = ReturnRecord::query()
            ->with('lines')
            ->when($ownerPartyId !== null, static function (Builder $query) use ($ownerPartyId): void {
                $query->where('owner_party_id', $ownerPartyId);
            })
            ->when($status !== null, static function (Builder $query) use ($status): void {
                $query->where('status', $status);
            })
            ->orderBy('created_at')
            ->limit(1000)
            ->get();

        return array_values($records->map(fn (ReturnRecord $record): ReturnOrder => $this->mapper->toDomain($record))->all());
    }
}
