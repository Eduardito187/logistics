<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\RegisterPushSubscription;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Domain\Contract\PushSubscriptionRepository;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\TrackingPushSubscription;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\WebPushSubscription;

/**
 * Guarda la suscripción Web Push del navegador contra el envío del token (K-04).
 *
 * Resuelve el seguimiento por el token público (token inválido o inexistente ⇒ TrackingException, que
 * el controlador traduce a 404 genérico). No hace I/O externo ni emite eventos: solo persiste la
 * suscripción estándar. El envío real de notificaciones lo hace el relay del outbox reutilizando el
 * WebPushChannelSender existente (ADR 014); este handler no lo toca. Idempotente por endpoint (upsert).
 *
 * @implements CommandHandler<RegisterPushSubscription>
 */
final class RegisterPushSubscriptionHandler implements CommandHandler
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly TrackingRepository $trackings,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RegisterPushSubscription) {
            throw TrackingException::because('RegisterPushSubscriptionHandler solo maneja RegisterPushSubscription');
        }

        $token = TrackingToken::fromString($command->token);

        $tracking = $this->trackings->findByToken($token);
        if ($tracking === null) {
            throw TrackingException::because('no existe seguimiento para ese token');
        }

        $subscription = TrackingPushSubscription::register(
            $this->subscriptions->nextIdentity(),
            $tracking->shipmentId(),
            $token->toString(),
            WebPushSubscription::of($command->endpoint, $command->p256dh, $command->auth),
            $this->clock->now(),
        );

        $this->subscriptions->save($subscription);
    }
}
