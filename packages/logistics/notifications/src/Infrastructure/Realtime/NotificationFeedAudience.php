<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Realtime;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Audiencia del log de notificaciones: a quién se le avisa cuando el log cambia.
 *
 * El log (`/app/notifications`, `/app/notifications/all`) es GLOBAL: las notificaciones son avisos al
 * cliente anclados a un envío, sin destinatario usuario, y la lista no se filtra por rol ni ciudad. Por
 * defecto se avisa a cualquier persona con rol (`*`), porque la app dejó de refrescar esa pantalla por
 * tiempo; se puede acotar a ciertos roles en `logistics-notifications.realtime.audience_roles` si el
 * volumen lo pide, y hay un tope duro de destinatarios.
 *
 * Se resuelve SIEMPRE dentro de un trabajo de la cola, nunca en un request: una consulta por el índice de
 * `user_roles.role`, con tope duro de destinatarios. Tolerante: ante cualquier error, audiencia vacía.
 */
final class NotificationFeedAudience
{
    /** Audiencia por defecto: cualquier persona con rol (la pantalla la usan todos los roles de la app). */
    private const DEFAULT_ROLES = ['*'];

    /** Tope de destinatarios por defecto. */
    private const DEFAULT_MAX_RECIPIENTS = 2000;

    /**
     * Ids de usuario de la audiencia, ordenados y sin repetir, recortados al tope configurado.
     *
     * @return list<int>
     */
    public function userIds(): array
    {
        $roles = $this->roles();
        if ($roles === []) {
            return [];
        }

        try {
            $query = DB::table('user_roles');
            if (!in_array('*', $roles, true)) {
                $query->whereIn('role', $roles);
            }

            $rows = $query->distinct()->orderBy('user_id')->limit($this->maxRecipients())->pluck('user_id');
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $ids = [];
        foreach ($rows as $userId) {
            if (is_numeric($userId) && (int) $userId > 0) {
                $ids[] = (int) $userId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        $configured = config('logistics-notifications.realtime.audience_roles', self::DEFAULT_ROLES);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (!is_array($configured)) {
            return self::DEFAULT_ROLES;
        }

        $roles = [];
        foreach ($configured as $role) {
            if (is_string($role) && trim($role) !== '') {
                $roles[] = strtolower(trim($role));
            }
        }

        return array_values(array_unique($roles));
    }

    private function maxRecipients(): int
    {
        $max = config('logistics-notifications.realtime.max_recipients', self::DEFAULT_MAX_RECIPIENTS);

        return is_numeric($max) && (int) $max > 0 ? (int) $max : self::DEFAULT_MAX_RECIPIENTS;
    }
}
