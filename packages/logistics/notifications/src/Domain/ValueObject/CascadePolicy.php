<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Política de cascada: lista ordenada de canales a intentar y la condición que dispara el salto al
 * siguiente (ver EscalateCondition). Es un contrato de dominio inmutable; las reglas concretas por
 * tipo de mensaje las resuelve un NotificationRulesProvider en la capa de aplicación.
 *
 * La cascada por defecto del ADR 006 es PushNtfy → WebPush → Email → Sms.
 */
final readonly class CascadePolicy
{
    /**
     * @param list<Channel> $channels
     */
    private function __construct(
        public array $channels,
        public EscalateCondition $escalateWhen,
    ) {}

    /**
     * @param list<Channel> $channels
     */
    public static function of(array $channels, EscalateCondition $escalateWhen = EscalateCondition::NoOpen): self
    {
        if ($channels === []) {
            throw NotificationsException::because('la política de cascada necesita al menos un canal');
        }

        $seen = [];
        foreach ($channels as $channel) {
            if (isset($seen[$channel->value])) {
                throw NotificationsException::because("el canal {$channel->value} está repetido en la política");
            }
            $seen[$channel->value] = true;
        }

        return new self(array_values($channels), $escalateWhen);
    }

    /**
     * Primer canal de la cascada: por donde arranca toda notificación con esta política.
     */
    public function first(): Channel
    {
        return $this->channels[0];
    }

    /**
     * Canal que sigue a $current en la cascada, o null si $current es el último (cascada agotada).
     */
    public function next(Channel $current): ?Channel
    {
        $index = array_search($current, $this->channels, true);
        if ($index === false) {
            throw NotificationsException::because("el canal {$current->value} no pertenece a esta política");
        }

        return $this->channels[$index + 1] ?? null;
    }

    public function contains(Channel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }

    /**
     * Forma primitiva para persistir en jsonb (regla 7 de CLAUDE.md: se cachea/guarda el array).
     *
     * @return array{channels: list<string>, escalate_when: string}
     */
    public function toArray(): array
    {
        return [
            'channels' => array_map(static fn (Channel $c): string => $c->value, $this->channels),
            'escalate_when' => $this->escalateWhen->value,
        ];
    }

    /**
     * Reconstruye la política desde su forma primitiva.
     *
     * @param array{channels: list<string>, escalate_when?: string} $data
     */
    public static function fromArray(array $data): self
    {
        $channels = array_map(static fn (string $c): Channel => Channel::from($c), $data['channels']);
        $escalateWhen = isset($data['escalate_when'])
            ? EscalateCondition::from($data['escalate_when'])
            : EscalateCondition::NoOpen;

        return self::of($channels, $escalateWhen);
    }
}
