<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Query\Expression;
use Logistics\Outbox\Domain\Contract\OutboxReader;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Lado de LECTURA del outbox sobre Eloquent/PostgreSQL. Consultas simples (sin bloqueo ni lease): el
 * panel y la API solo observan. El relay sigue usando OutboxStore para el pull transaccional.
 */
final readonly class EloquentOutboxReader implements OutboxReader
{
    public function findById(OutboxMessageId $id): ?OutboxMessage
    {
        $record = OutboxMessageRecord::query()->find($id->toString());

        return $record instanceof OutboxMessageRecord ? OutboxMessageMapper::toEntity($record) : null;
    }

    public function list(?OutboxStatus $status, int $limit, int $offset): array
    {
        $query = OutboxMessageRecord::query()->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        /** @var list<OutboxMessage> $messages */
        $messages = $query
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(static fn (OutboxMessageRecord $record): OutboxMessage => OutboxMessageMapper::toEntity($record))
            ->all();

        return $messages;
    }

    public function count(?OutboxStatus $status): int
    {
        $query = OutboxMessageRecord::query();

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query->count();
    }

    public function countByStatus(): array
    {
        /** @var array<string, int> $counts */
        $counts = OutboxMessageRecord::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck(new Expression('aggregate'), 'status')
            ->map(static fn (int | string $value): int => (int) $value)
            ->all();

        // Garantiza que todos los estados aparezcan, los vacíos en 0.
        $result = [];
        foreach (OutboxStatus::cases() as $status) {
            $result[$status->value] = $counts[$status->value] ?? 0;
        }

        return $result;
    }
}
