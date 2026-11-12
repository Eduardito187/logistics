<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;

/**
 * Implementación Eloquent del repositorio de avisos a SAP.
 *
 * El registro es un insert-or-ignore sobre el índice único de `event_id` (idempotente ante la reentrega
 * at-least-once del outbox). Va envuelto en una transacción propia: si ya hay una transacción abierta
 * (p. ej. un test o un llamador transaccional) Laravel la convierte en SAVEPOINT, así un error de esta
 * escritura nunca deja abortada la transacción del llamador.
 */
final class EloquentSapNotificationRepository implements SapNotificationRepository
{
    public function __construct(
        private readonly SapNotificationMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): SapNotificationId
    {
        return SapNotificationId::generate();
    }

    public function registerIfAbsent(SapNotification $notification): bool
    {
        $row = $this->mapper->toInsertRow($notification, new DateTimeImmutable);

        $connection = SapNotificationRecord::query()->getModel()->getConnectionName();

        /** @var int $inserted */
        $inserted = $this->connections->connection($connection)->transaction(
            static fn (): int => SapNotificationRecord::query()->insertOrIgnore([$row]),
        );

        return $inserted > 0;
    }

    public function save(SapNotification $notification): void
    {
        SapNotificationRecord::query()
            ->whereKey($notification->id()->toString())
            ->update([
                ...$this->mapper->toStateRow($notification),
                'updated_at' => (new DateTimeImmutable)->format('Y-m-d H:i:sP'),
            ]);
    }

    public function findById(SapNotificationId $id): ?SapNotification
    {
        $record = SapNotificationRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByEventId(string $eventId): ?SapNotification
    {
        $record = SapNotificationRecord::query()->where('event_id', trim($eventId))->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function dueForRetry(int $maxAttempts, DateTimeImmutable $now, int $limit): array
    {
        return SapNotificationRecord::query()
            ->whereIn('status', [SapNotificationStatus::Pending->value, SapNotificationStatus::Failed->value])
            ->where('attempts', '<', max(1, $maxAttempts))
            ->where(static function (Builder $query) use ($now): void {
                /** @var Builder<SapNotificationRecord> $query */
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now->format('Y-m-d H:i:sP'));
            })
            ->orderBy('created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (SapNotificationRecord $record): SapNotification => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
