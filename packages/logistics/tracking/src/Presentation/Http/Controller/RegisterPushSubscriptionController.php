<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Logistics\Tracking\Application\Command\RegisterPushSubscription\RegisterPushSubscription;
use Logistics\Tracking\Presentation\Http\ApiResponder;
use Logistics\Tracking\Presentation\Http\Request\RegisterPushSubscriptionRequest;

/**
 * Endpoint PÚBLICO (por token) para guardar la suscripción Web Push del navegador (K-04).
 *
 * La única credencial es el token opaco de la URL; el rate limit lo pone el middleware de la ruta. No
 * recibe ni devuelve PII. El guardado lo hace el CommandBus; el dominio valida la forma de la
 * suscripción. Un token inválido/inexistente se traduce a 404 genérico (no revela si el token existe) y
 * una suscripción malformada a 422. El envío real de notificaciones NO se hace aquí: lo hace el relay
 * del outbox reutilizando el WebPushChannelSender existente (ADR 014).
 *
 * Presentation habla con Application vía el CommandBus; nunca toca Infrastructure ni el dominio.
 */
final class RegisterPushSubscriptionController
{
    use ApiResponder;

    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(RegisterPushSubscriptionRequest $request, string $token): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RegisterPushSubscription(
                token: $token,
                endpoint: $request->endpoint(),
                p256dh: $request->p256dh(),
                auth: $request->auth(),
            ));
        } catch (DomainException $exception) {
            // Token inexistente o suscripción malformada: no se distingue para no filtrar el token.
            return $this->error('subscription_rejected', $exception->getMessage(), 404);
        }

        return $this->data(['subscribed' => true], 201);
    }
}
