<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Logistics\Notifications\Application\Contract\NotificationInboxReader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lado lectura de la bandeja de la app: no leídas, conteo y estado de lectura de una página.
 *
 * Una notificación está leída por el usuario si tiene fila en notification_reads o si su created_at
 * cae dentro de la marca de agua de «leer todas» (notification_read_marks.read_up_to). El filtro de no
 * leídas es: created_at > read_up_to AND NOT EXISTS (fila de lectura) — ambos con índice (created_at y
 * la PK compuesta), recortado en SQL con LIMIT/OFFSET.
 */
final class EloquentNotificationInboxReader implements NotificationInboxReader
{
    public function __construct(
        private readonly NotificationMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function unreadFor(string $userId, int $limit, int $offset): array
    {
        $records = $this->unreadQuery($userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset(max(0, $offset))
            ->limit(max(1, $limit))
            ->get();

        $notifications = [];
        foreach ($records as $record) {
            try {
                $notifications[] = $this->mapper->toDomain($record);
            } catch (Throwable $e) {
                // Misma tolerancia que el log global: una fila irreconstruible se omite con log.
                $this->logger?->warning('Notificación omitida de la bandeja: fila no reconstruible', [
                    'notification_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notifications;
    }

    public function countUnread(string $userId): int
    {
        return $this->unreadQuery($userId)->count();
    }

    public function readStates(?string $userId, array $notificationIds): array
    {
        if ($notificationIds === []) {
            return [];
        }

        $connection = $this->connections->connection();

        $createdAt = [];
        foreach ($connection->table('notifications')->whereIn('id', $notificationIds)->get(['id', 'created_at']) as $row) {
            $createdAt[(string) $row->id] = NotificationTimestamps::fromColumn($row->created_at ?? null);
        }

        $explicit = [];
        $mark = null;
        if ($userId !== null) {
            $reads = $connection->table('notification_reads')
                ->where('user_id', $userId)
                ->whereIn('notification_id', $notificationIds)
                ->get(['notification_id', 'read_at']);
            foreach ($reads as $row) {
                $explicit[(string) $row->notification_id] = NotificationTimestamps::fromColumn($row->read_at ?? null);
            }

            $mark = $this->watermark($userId);
        }

        $states = [];
        foreach ($notificationIds as $id) {
            $created = $createdAt[$id] ?? null;
            $readAt = $explicit[$id] ?? null;

            // Sin lectura propia: queda leída si entra en la marca de agua de «leer todas».
            if ($readAt === null && $mark !== null && $created !== null && $created <= $mark['up_to']) {
                $readAt = $mark['marked_at'];
            }

            $states[$id] = [
                'created_at' => NotificationTimestamps::toIso($created),
                'read_at' => NotificationTimestamps::toIso($readAt),
            ];
        }

        return $states;
    }

    /**
     * Consulta base de no leídas por el usuario (sin orden ni recorte).
     *
     * @return Builder<NotificationRecord>
     */
    private function unreadQuery(string $userId): Builder
    {
        $mark = $this->watermark($userId);

        $query = NotificationRecord::query();

        if ($mark !== null) {
            $query->where('created_at', '>', NotificationTimestamps::toColumn($mark['up_to']));
        }

        $query->whereNotExists(static function (QueryBuilder $reads) use ($userId): void {
            $reads->selectRaw('1')
                ->from('notification_reads')
                ->where('notification_reads.user_id', $userId)
                ->whereColumn('notification_reads.notification_id', 'notifications.id');
        });

        return $query;
    }

    /**
     * Marca de agua de «leer todas» del usuario, o null si nunca la usó.
     *
     * @return array{up_to: DateTimeImmutable, marked_at: DateTimeImmutable}|null
     */
    private function watermark(string $userId): ?array
    {
        $row = $this->connections->connection()->table('notification_read_marks')
            ->where('user_id', $userId)
            ->first(['read_up_to', 'marked_at']);

        if ($row === null) {
            return null;
        }

        $upTo = NotificationTimestamps::fromColumn($row->read_up_to ?? null);
        $markedAt = NotificationTimestamps::fromColumn($row->marked_at ?? null);

        if ($upTo === null) {
            return null;
        }

        return ['up_to' => $upTo, 'marked_at' => $markedAt ?? $upTo];
    }
}
