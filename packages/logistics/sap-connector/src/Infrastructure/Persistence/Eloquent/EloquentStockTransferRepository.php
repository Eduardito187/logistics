<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;

/**
 * Implementación Eloquent del repositorio de traspasos de stock.
 *
 * save() es un upsert por id. La idempotencia de ingreso se respalda en el índice único sobre
 * `event_id`. `pending()` incluye Pending y Failed (lo despachable); `failed()` solo Failed.
 */
final class EloquentStockTransferRepository implements StockTransferRepository
{
    public function __construct(
        private readonly StockTransferMapper $mapper,
    ) {}

    public function nextIdentity(): StockTransferId
    {
        return StockTransferId::generate();
    }

    public function save(StockTransfer $transfer): void
    {
        StockTransferRecord::query()->updateOrCreate(
            ['id' => $transfer->id()->toString()],
            $this->mapper->toRow($transfer),
        );
    }

    public function findById(StockTransferId $id): ?StockTransfer
    {
        $record = StockTransferRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByEventId(string $eventId): ?StockTransfer
    {
        $record = StockTransferRecord::query()->where('event_id', trim($eventId))->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function pending(): array
    {
        return StockTransferRecord::query()
            ->whereIn('status', [TransferStatus::Pending->value, TransferStatus::Failed->value])
            ->orderBy('id')
            ->get()
            ->map(fn (StockTransferRecord $record): StockTransfer => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }

    public function failed(): array
    {
        return StockTransferRecord::query()
            ->where('status', TransferStatus::Failed->value)
            ->orderBy('id')
            ->get()
            ->map(fn (StockTransferRecord $record): StockTransfer => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
