<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Rules;

use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Reglas de cascada por defecto: para todo tipo de mensaje, la cascada va de lo gratuito a lo caro,
 * escalando cuando no hay apertura: PushNtfy → WebPush → Telegram → Email → Sms.
 *
 * NOTA (decisión del dueño, 2026-10): TODO el stack de notificaciones es open-source y gratis. El push
 * ya NO usa Firebase/FCM (se reemplazó por ntfy/UnifiedPush self-host) y se quitó WhatsApp (Meta/Twilio,
 * de pago). Telegram (gratis/open) queda como canal de mensajería antes del email; el SMS crítico (por
 * gateway open-source) sigue al final. El detalle queda en el README.
 *
 * Mañana esto puede leerse de base o de un panel (PersistedNotificationRulesProvider); hoy es el default.
 */
final class DefaultNotificationRulesProvider implements NotificationRulesProvider
{
    public function policyFor(MessageType $messageType): CascadePolicy
    {
        // La cascada completa aplica a todos los tipos; el escalado al SMS crítico solo se alcanza como
        // respaldo cuando no hubo apertura en los canales previos.
        return CascadePolicy::of(
            [
                Channel::PushNtfy,
                Channel::WebPush,
                Channel::Telegram,
                Channel::Email,
                Channel::Sms,
            ],
            EscalateCondition::NoOpen,
        );
    }
}
