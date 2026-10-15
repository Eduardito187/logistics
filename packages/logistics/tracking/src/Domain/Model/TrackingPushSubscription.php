<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Model;

use DateTimeImmutable;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\PushSubscriptionId;
use Logistics\Tracking\Domain\ValueObject\WebPushSubscription;

/**
 * Suscripción de un navegador a las notificaciones Web Push del envío (K-04).
 *
 * La crea el cliente al pulsar "Notify me" en la página pública de rastreo. Guarda la suscripción
 * estándar (endpoint + claves p256dh/auth) junto al envío al que pertenece. NO dispara I/O externo: el
 * envío real de web push lo hace el relay del outbox reutilizando el WebPushChannelSender existente
 * (ADR 014), así que este agregado no libera eventos de dominio: es un simple registro de suscripción.
 *
 * Es idempotente por endpoint (el navegador puede re-suscribir): la persistencia hace upsert por
 * endpoint, de modo que re-suscribir refresca los datos sin duplicar la fila.
 */
final class TrackingPushSubscription
{
    private function __construct(
        private readonly PushSubscriptionId $id,
        private readonly string $shipmentId,
        private readonly string $token,
        private readonly WebPushSubscription $subscription,
        private readonly DateTimeImmutable $subscribedAt,
    ) {}

    public static function register(
        PushSubscriptionId $id,
        string $shipmentId,
        string $token,
        WebPushSubscription $subscription,
        DateTimeImmutable $subscribedAt,
    ): self {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('el id del envío no puede estar vacío');
        }

        return new self($id, $shipmentId, $token, $subscription, $subscribedAt);
    }

    public function id(): PushSubscriptionId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function subscription(): WebPushSubscription
    {
        return $this->subscription;
    }

    public function subscribedAt(): DateTimeImmutable
    {
        return $this->subscribedAt;
    }
}
