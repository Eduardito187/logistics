<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Implementación PostgreSQL del outbox sobre Eloquent.
 *
 * Idempotencia: append() usa insertOrIgnore, de modo que un segundo intento con el mismo event_id
 * (índice único) no inserta nada ni lanza. Así, reencolar el mismo evento es inocuo.
 *
 * Concurrencia: pullBatch() selecciona con FOR UPDATE SKIP LOCKED dentro de una transacción, para que
 * dos workers corriendo en paralelo nunca tomen la misma fila. Además corre available_at hacia el
 * futuro (lease) sobre las filas tomadas: si el worker muere a mitad del despacho, el mensaje vuelve a
 * estar disponible recién pasado el lease, evitando que otro lo tome de inmediato y lo duplique.
 */
final readonly class EloquentOutboxStore implements OutboxStore
{
    /** Segundos que un mensaje tomado queda "en vuelo" antes de volver a ser elegible, por defecto. */
    private const DEFAULT_LEASE_SECONDS = 60;

    private int $leaseSeconds;

    public function __construct(
        private ConnectionResolverInterface $connections,
        ?int $leaseSeconds = null,
    ) {
        $this->leaseSeconds = $leaseSeconds ?? self::DEFAULT_LEASE_SECONDS;
    }

    public function append(OutboxMessage $message): void
    {
        OutboxMessageRecord::query()->insertOrIgnore([OutboxMessageMapper::toRow($message)]);
    }

    public function pullBatch(int $limit, DateTimeImmutable $now): array
    {
        /** @var list<OutboxMessage> $messages */
        $messages = $this->connections->connection()->transaction(function () use ($limit, $now): array {
            $records = OutboxMessageRecord::query()
                ->whereIn('status', array_map(
                    static fn (OutboxStatus $status): string => $status->value,
                    OutboxStatus::dispatchable(),
                ))
                ->where('available_at', '<=', $now)
                ->orderBy('available_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($records->isEmpty()) {
                return [];
            }

            // Lease: corremos available_at para que otro worker no reintente estas filas mientras
            // este las despacha. El estado definitivo lo fija save() tras despachar/fallar.
            $leaseUntil = $now->add(new DateInterval('PT'.$this->leaseSeconds.'S'));
            OutboxMessageRecord::query()
                ->whereIn('id', $records->pluck('id')->all())
                ->update(['available_at' => $leaseUntil->format('Y-m-d H:i:sP')]);

            return $records
                ->map(static fn (OutboxMessageRecord $record): OutboxMessage => OutboxMessageMapper::toEntity($record))
                ->all();
        });

        return $messages;
    }

    public function save(OutboxMessage $message): void
    {
        $row = OutboxMessageMapper::toRow($message);
        unset($row['id']);

        OutboxMessageRecord::query()
            ->where('id', $message->id()->toString())
            ->update($row);
    }
}
