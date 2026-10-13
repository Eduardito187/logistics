<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Rules;

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Throwable;

/**
 * Códec puro entre la forma primitiva de la clave de configuración `notifications.cascade_rules` (lo que
 * el contexto Configuración versiona y sirve) y las políticas de cascada por tipo de mensaje del dominio.
 *
 * La clave guarda SOLO los overrides del operador (un mapa disperso tipo→política); los tipos que no
 * aparecen usan la cascada por defecto del ADR 006 (DefaultNotificationRulesProvider). Forma primitiva:
 *
 *   { "rules": { "<MESSAGE_TYPE>": { "channels": ["PUSH_FCM", ...], "escalate_when": "NO_OPEN" } } }
 *
 * La decodificación es TOLERANTE: ignora entradas malformadas o tipos desconocidos en vez de reventar,
 * porque el valor puede editarse a mano desde el panel de configuración genérico.
 */
final class CascadeRuleCodec
{
    /**
     * Clave namespaced de la regla de cascada en el contexto Configuración (versionada, con historial).
     */
    public const SETTING_KEY = 'notifications.cascade_rules';

    /**
     * Decodifica el valor primitivo a las políticas de override por tipo de mensaje (keyed por el valor
     * del enum MessageType). Un valor nulo o sin `rules` devuelve un mapa vacío (todo usa el default).
     *
     * @param  array<string, mixed>|null    $value
     * @return array<string, CascadePolicy>
     */
    public static function decode(?array $value): array
    {
        $policies = [];
        foreach (self::rulesOf($value ?? []) as $type => $entry) {
            if (!is_string($type) || MessageType::tryFrom($type) === null) {
                continue;
            }

            $policy = self::decodeEntry($entry);
            if ($policy !== null) {
                $policies[$type] = $policy;
            }
        }

        return $policies;
    }

    /**
     * Devuelve el valor con el override del tipo dado agregado o reemplazado (upsert).
     *
     * @param  array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function upsert(array $current, MessageType $type, CascadePolicy $policy): array
    {
        $rules = self::rulesOf($current);
        $rules[$type->value] = $policy->toArray();
        $current['rules'] = $rules;

        return $current;
    }

    /**
     * Devuelve el valor sin el override del tipo dado (vuelve a la cascada por defecto para ese tipo).
     *
     * @param  array<string, mixed> $current
     * @return array<string, mixed>
     */
    public static function remove(array $current, MessageType $type): array
    {
        $rules = self::rulesOf($current);
        unset($rules[$type->value]);
        $current['rules'] = $rules;

        return $current;
    }

    /**
     * Extrae el sub-mapa `rules` del valor, o uno vacío si no está o no es un arreglo.
     *
     * @param  array<string, mixed>    $value
     * @return array<array-key, mixed>
     */
    private static function rulesOf(array $value): array
    {
        $rules = $value['rules'] ?? null;

        return is_array($rules) ? $rules : [];
    }

    /**
     * Construye una política desde una entrada primitiva, o null si está malformada.
     */
    private static function decodeEntry(mixed $entry): ?CascadePolicy
    {
        if (!is_array($entry) || !isset($entry['channels']) || !is_array($entry['channels'])) {
            return null;
        }

        $channels = [];
        foreach ($entry['channels'] as $channel) {
            if (!is_string($channel)) {
                return null;
            }
            $channels[] = $channel;
        }

        $escalateWhen = $entry['escalate_when'] ?? null;

        try {
            return is_string($escalateWhen)
                ? CascadePolicy::fromArray(['channels' => $channels, 'escalate_when' => $escalateWhen])
                : CascadePolicy::fromArray(['channels' => $channels]);
        } catch (Throwable) {
            // Canales o condición inválidos: se descarta la entrada (tolerancia a config editada a mano).
            return null;
        }
    }
}
