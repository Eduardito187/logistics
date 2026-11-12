<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Contract;

use Logistics\Notifications\Domain\Model\Notification;

/**
 * Puerta de lectura de la bandeja de notificaciones de la app (lado consulta del CQRS): filtro de no
 * leídas, conteo de no leídas y estado de lectura de una página, cada uno por usuario.
 *
 * La implementación baja el filtro y el conteo a SQL (NOT EXISTS sobre la PK compuesta + marca de agua
 * de «leer todas»), sin materializar la colección en memoria.
 */
interface NotificationInboxReader
{
    /**
     * Notificaciones NO leídas por el usuario, de la más reciente a la más antigua, paginadas.
     *
     * @return list<Notification>
     */
    public function unreadFor(string $userId, int $limit, int $offset): array;

    /**
     * Cantidad de notificaciones no leídas por el usuario (badge de la campana).
     */
    public function countUnread(string $userId): int;

    /**
     * Estado de lectura de un lote de notificaciones (una página): fecha de creación y, si hay usuario,
     * cuándo la leyó (null = no leída). Fechas en ISO 8601. Sin usuario, read_at es siempre null.
     *
     * @param  list<string>                                                        $notificationIds
     * @return array<string, array{created_at: string|null, read_at: string|null}>
     */
    public function readStates(?string $userId, array $notificationIds): array;
}
