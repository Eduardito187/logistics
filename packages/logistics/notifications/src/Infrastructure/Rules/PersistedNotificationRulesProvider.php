<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Rules;

use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Reglas de cascada configurables (P1-NOT-01): devuelve la política personalizada del tipo de mensaje
 * si el operador la definió en el panel, y si no, cae a la cascada por defecto del ADR 006.
 *
 * Es el binding de producción del NotificationRulesProvider; reemplaza al DefaultNotificationRulesProvider
 * (que queda como fuente del default). Así las reglas se cambian desde el admin sin tocar el dominio.
 */
final readonly class PersistedNotificationRulesProvider implements NotificationRulesProvider
{
    public function __construct(
        private MessageTypeRuleRepository $rules,
        private DefaultNotificationRulesProvider $defaults,
    ) {}

    public function policyFor(MessageType $messageType): CascadePolicy
    {
        return $this->rules->policyFor($messageType) ?? $this->defaults->policyFor($messageType);
    }
}
