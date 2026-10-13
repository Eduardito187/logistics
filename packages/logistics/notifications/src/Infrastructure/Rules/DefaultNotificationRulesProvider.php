<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Rules;

use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Reglas de cascada por defecto (ADR 006): para todo tipo de mensaje, la cascada va de lo gratuito a
 * lo caro, escalando cuando no hay apertura: PushFcm → WebPush → Email → Sms.
 *
 * Mañana esto puede leerse de base o de un panel; hoy es la configuración fija del ADR.
 */
final class DefaultNotificationRulesProvider implements NotificationRulesProvider
{
    public function policyFor(MessageType $messageType): CascadePolicy
    {
        // La cascada completa aplica a todos los tipos; el escalado a SMS solo se alcanza como respaldo
        // crítico cuando no hubo apertura en los canales previos.
        return CascadePolicy::of(
            [Channel::PushFcm, Channel::WebPush, Channel::Email, Channel::Sms],
            EscalateCondition::NoOpen,
        );
    }
}
