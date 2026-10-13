<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Destinatario de una notificación. El canal primario (push / web push) va por el topic FCM del envío,
 * sin datos de contacto; email y SMS son respaldos y necesitan, respectivamente, un email o un teléfono.
 *
 * La plataforma no guarda tokens de la app de clientes (ADR 006): el destinatario se identifica por el
 * envío, y los contactos de respaldo son opcionales.
 */
final readonly class Recipient
{
    private function __construct(
        public string $shipmentId,
        public ?string $email,
        public ?string $phone,
    ) {}

    public static function of(string $shipmentId, ?string $email = null, ?string $phone = null): self
    {
        $trimmedShipment = trim($shipmentId);
        if ($trimmedShipment === '') {
            throw NotificationsException::because('el destinatario necesita un id de envío');
        }

        $trimmedEmail = $email !== null ? trim($email) : null;
        if ($trimmedEmail === '') {
            $trimmedEmail = null;
        }
        if ($trimmedEmail !== null && !str_contains($trimmedEmail, '@')) {
            throw NotificationsException::because('el email de respaldo no es válido');
        }

        $trimmedPhone = $phone !== null ? trim($phone) : null;
        if ($trimmedPhone === '') {
            $trimmedPhone = null;
        }

        return new self($trimmedShipment, $trimmedEmail, $trimmedPhone);
    }

    /**
     * Topic FCM del envío de este destinatario (canal primario y web push).
     */
    public function fcmTopic(): FcmTopic
    {
        return FcmTopic::forShipment($this->shipmentId);
    }

    /**
     * Indica si el destinatario tiene contacto para el canal dado. Push y web push van por topic y no
     * requieren contacto; email y SMS sí.
     */
    public function canReceiveVia(Channel $channel): bool
    {
        return match ($channel) {
            Channel::PushFcm, Channel::WebPush => true,
            Channel::Email => $this->email !== null,
            Channel::Sms => $this->phone !== null,
        };
    }
}
