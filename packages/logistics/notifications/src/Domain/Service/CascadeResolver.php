<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Service;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;

/**
 * Servicio de dominio: dado el estado de una notificación, decide el siguiente canal según su política
 * de cascada. Devuelve null cuando el canal actual es el último (cascada agotada).
 *
 * No muta la notificación: solo calcula. Quien decide escalar es la capa de aplicación, llamando a
 * Notification::escalate().
 */
final class CascadeResolver
{
    public function nextChannel(Notification $notification): ?Channel
    {
        return $notification->policy()->next($notification->currentChannel());
    }

    /**
     * ¿Queda algún canal por intentar después del actual?
     */
    public function canEscalate(Notification $notification): bool
    {
        return $this->nextChannel($notification) !== null;
    }
}
