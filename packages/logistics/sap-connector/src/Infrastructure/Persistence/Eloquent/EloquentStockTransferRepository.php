<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        $record = StockTransferRecord::query()->updateOrCreate(
            ['id' => $transfer->id()->toString()],
            $this->mapper->toRow($transfer),
        );

        // updated_at hace de "último intento" para el backoff de reconciliación: un reintento que vuelve
        // a fallar con el mismo motivo no cambia ninguna columna, así que se lo toca explícitamente.
        if (!$record->wasRecentlyCreated && !$record->wasChanged()) {
            $record->touch();
        }
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

    public function dispatchable(?DateTimeImmutable $pendingUpdatedBefore, DateTimeImmutable $failedUpdatedBefore, int $limit): array
    {
        return StockTransferRecord::query()
            ->where(static function (Builder $query) use ($pendingUpdatedBefore, $failedUpdatedBefore): void {
                /** @var Builder<StockTransferRecord> $query */
                $query->where(static function (Builder $failed) use ($failedUpdatedBefore): void {
                    /** @var Builder<StockTransferRecord> $failed */
                    $failed->where('status', TransferStatus::Failed->value)
                        ->where('updated_at', '<=', $failedUpdatedBefore->format('Y-m-d H:i:sP'));
                });

                if ($pendingUpdatedBefore !== null) {
                    $query->orWhere(static function (Builder $pending) use ($pendingUpdatedBefore): void {
                        /** @var Builder<StockTransferRecord> $pending */
                        $pending->where('status', TransferStatus::Pending->value)
                            ->where('updated_at', '<=', $pendingUpdatedBefore->format('Y-m-d H:i:sP'));
                    });
                }
            })
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
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
