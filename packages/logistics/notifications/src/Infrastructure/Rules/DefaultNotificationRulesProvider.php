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
 * escalando cuando no hay apertura: PushFcm → WebPush → Telegram → Email → WhatsApp → Sms.
 *
 * NOTA (decisión del dueño, 2026-10): el ADR 006 original fijaba "PushFcm → WebPush → Email → Sms, sin
 * WhatsApp" por costo. El dueño pidió sumar WhatsApp y Telegram; se insertan en la cascada por orden de
 * costo (Telegram gratis antes de email; WhatsApp antes del SMS crítico). El detalle queda en el README.
 *
 * Mañana esto puede leerse de base o de un panel (PersistedNotificationRulesProvider); hoy es el default.
 */
final class DefaultNotificationRulesProvider implements NotificationRulesProvider
{
    public function policyFor(MessageType $messageType): CascadePolicy
    {
        // La cascada completa aplica a todos los tipos; el escalado a WhatsApp/SMS solo se alcanza como
        // respaldo cuando no hubo apertura en los canales previos.
        return CascadePolicy::of(
            [
                Channel::PushFcm,
                Channel::WebPush,
                Channel::Telegram,
                Channel::Email,
                Channel::WhatsApp,
                Channel::Sms,
            ],
            EscalateCondition::NoOpen,
        );
    }
}
