<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\RegisterPushSubscription;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando: un navegador se suscribe a las notificaciones Web Push del envío (K-04).
 *
 * El seguimiento se identifica por el token público del enlace. endpoint/p256dh/auth son los datos
 * estándar que devuelve `PushManager.subscribe` en el navegador del cliente.
 */
final readonly class RegisterPushSubscription implements Command
{
    public function __construct(
        public string $token,
        public string $endpoint,
        public string $p256dh,
        public string $auth,
    ) {}
}
