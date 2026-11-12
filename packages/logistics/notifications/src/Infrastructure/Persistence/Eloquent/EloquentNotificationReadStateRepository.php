<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Notifications\Domain\Contract\NotificationReadStateRepository;
use Logistics\Notifications\Domain\ValueObject\NotificationId;

/**
 * Implementación SQL del estado de lectura por usuario (tablas notification_reads y
 * notification_read_marks). Seguro ante concurrencia sin locks explícitos:
 *
 *  - markRead: INSERT ... ON CONFLICT DO NOTHING sobre la PK (user_id, notification_id). Dos teléfonos
 *    del mismo usuario marcando a la vez no chocan y se conserva el primer read_at (idempotente).
 *  - markAllRead: UPSERT monótono (solo avanza read_up_to), así un «leer todas» viejo que llega tarde
 *    por la cola offline no "des-lee" lo que otro dispositivo ya marcó.
 */
final class EloquentNotificationReadStateRepository implements NotificationReadStateRepository
{
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function markRead(NotificationId $notificationId, string $userId, DateTimeImmutable $readAt): void
    {
        $this->connections->connection()->table('notification_reads')->insertOrIgnore([
            'user_id' => $userId,
            'notification_id' => $notificationId->toString(),
            'read_at' => NotificationTimestamps::toColumn($readAt),
        ]);
    }

    public function markAllRead(string $userId, DateTimeImmutable $upTo, DateTimeImmutable $markedAt): void
    {
        $upToColumn = NotificationTimestamps::toColumn($upTo);
        $markedColumn = NotificationTimestamps::toColumn($markedAt);

        // ON CONFLICT ... DO UPDATE ... WHERE: válido en PostgreSQL y SQLite (>= 3.24).
        $this->connections->connection()->statement(
            'INSERT INTO notification_read_marks (user_id, read_up_to, marked_at, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?, ?) '
            .'ON CONFLICT (user_id) DO UPDATE SET '
            .'read_up_to = excluded.read_up_to, marked_at = excluded.marked_at, updated_at = excluded.updated_at '
            .'WHERE notification_read_marks.read_up_to < excluded.read_up_to',
            [$userId, $upToColumn, $markedColumn, $markedColumn, $markedColumn],
        );
    }
}
