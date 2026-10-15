<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Contract;

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Fuente de las reglas de cascada por tipo de mensaje. Se lee como configuración: qué canales, en qué
 * orden y con qué condición de escalado aplican a cada MessageType.
 *
 * La implementación por defecto devuelve la cascada del ADR 006 (PushNtfy → WebPush → Email → Sms).
 * Mañana podría leerse de base o de un panel sin tocar el dominio.
 */
interface NotificationRulesProvider
{
    public function policyFor(MessageType $messageType): CascadePolicy;
}
