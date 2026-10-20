<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de DATOS (idempotente): normaliza los canales históricos del enum `Channel` al valor
 * vigente. El push se persistió como `PUSH_FCM` (primera versión) y `PUSH_NTFY` (transporte ntfy,
 * retirado); hoy es `FCM` (decisión del dueño 2026-10-16). Las filas viejas hacían reventar
 * `GET /app/notifications/all` con un 500.
 *
 * - notifications: `current_channel`, claves de `attempts` y `policy.channels` (sin duplicados).
 * - notification_message_type_rules: `channels` (se descartan `WEB_PUSH`/`WHATSAPP`, sin equivalente).
 * - notification_message_templates: `channel` solo si no existe ya la plantilla `FCM` equivalente
 *   (único por tipo+canal+locale). Las plantillas de canales retirados NO se borran: la lectura las omite.
 *
 * No borra filas. Correrla dos veces no cambia nada. Los alias viven acá (congelados), no en el enum,
 * para que la migración no dependa de la evolución del dominio.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const ALIASES = ['PUSH_FCM' => 'FCM', 'PUSH_NTFY' => 'FCM'];

    /** Canales vigentes al momento de esta migración. */
    private const CURRENT = ['FCM', 'TELEGRAM', 'EMAIL', 'SMS'];

    public function up(): void
    {
        // El trabajo se reparte en tres pasos independientes (mismo comportamiento que antes), cada uno
        // en su propio método para mantener la complejidad cognitiva bajo control.
        $this->normalizeNotifications();
        $this->normalizeTypeRules();
        $this->normalizeTemplates();
    }

    /**
     * Notificaciones: se recorren por lotes solo las que mencionan un alias (current o jsonb) y se
     * reescribe cada fila con los canales normalizados.
     */
    private function normalizeNotifications(): void
    {
        $legacy = array_keys(self::ALIASES);

        DB::table('notifications')
            ->where(function ($q) use ($legacy): void {
                $q->whereIn('current_channel', $legacy);
                foreach ($legacy as $alias) {
                    $q->orWhereRaw('policy::text like ?', ['%"'.$alias.'"%'])
                        ->orWhereRaw('attempts::text like ?', ['%"'.$alias.'"%']);
                }
            })
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $this->normalizeNotificationRow($row);
                }
            });
    }

    /**
     * Reescribe una notificación: alias → vigente en `current_channel`, `policy.channels` y `attempts`
     * (acumulando los intentos de los alias que colapsan al mismo canal vigente).
     */
    private function normalizeNotificationRow(stdClass $row): void
    {
        $policy = json_decode((string) $row->policy, true);
        $attempts = json_decode((string) $row->attempts, true);

        if (is_array($policy) && isset($policy['channels']) && is_array($policy['channels'])) {
            $policy['channels'] = $this->normalizeList($policy['channels'], keepUnknown: true);
        }

        $normalizedAttempts = [];
        foreach (is_array($attempts) ? $attempts : [] as $channel => $count) {
            $key = self::ALIASES[(string) $channel] ?? (string) $channel;
            $normalizedAttempts[$key] = ($normalizedAttempts[$key] ?? 0) + (int) $count;
        }

        DB::table('notifications')->where('id', $row->id)->update([
            'current_channel' => self::ALIASES[$row->current_channel] ?? $row->current_channel,
            'policy' => json_encode($policy),
            'attempts' => json_encode($normalizedAttempts),
        ]);
    }

    /**
     * Reglas por tipo: alias → vigente, descarta canales retirados; si no queda ninguno, no se toca.
     */
    private function normalizeTypeRules(): void
    {
        foreach (DB::table('notification_message_type_rules')->get() as $rule) {
            $channels = json_decode((string) $rule->channels, true);
            if (!is_array($channels)) {
                continue;
            }
            $normalized = $this->normalizeList($channels, keepUnknown: false);
            if ($normalized !== [] && $normalized !== $channels) {
                DB::table('notification_message_type_rules')
                    ->where('message_type', $rule->message_type)
                    ->update(['channels' => json_encode($normalized)]);
            }
        }
    }

    /**
     * Plantillas: renombra el canal solo si no choca con una plantilla FCM ya existente.
     */
    private function normalizeTemplates(): void
    {
        $legacy = array_keys(self::ALIASES);

        foreach (DB::table('notification_message_templates')->whereIn('channel', $legacy)->get() as $template) {
            $target = self::ALIASES[$template->channel];
            $exists = DB::table('notification_message_templates')
                ->where('message_type', $template->message_type)
                ->where('locale', $template->locale)
                ->where('channel', $target)
                ->exists();
            if (!$exists) {
                DB::table('notification_message_templates')
                    ->where('id', $template->id)
                    ->update(['channel' => $target]);
            }
        }
    }

    public function down(): void
    {
        // Normalización de datos sin vuelta atrás significativa (los alias son equivalentes).
    }

    /**
     * @param  array<mixed> $channels
     * @return list<string>
     */
    private function normalizeList(array $channels, bool $keepUnknown): array
    {
        $out = [];
        foreach ($channels as $channel) {
            $value = self::ALIASES[(string) $channel] ?? (string) $channel;
            if (!$keepUnknown && !in_array($value, self::CURRENT, true)) {
                continue;
            }
            if (!in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }
};
