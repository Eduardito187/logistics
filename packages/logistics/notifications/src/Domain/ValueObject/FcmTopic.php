<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Topic por envío (`shipment_<id>`). La app de clientes (push ntfy/UnifiedPush) y la página de tracking
 * (web push VAPID) se suscriben a él; la plataforma publica al topic SIN conocer tokens ni endpoints de
 * los dispositivos (ADR 006). Un topic por envío aísla a cada cliente: solo recibe las novedades de su
 * propio pedido.
 *
 * NOTA: el nombre de la clase (`FcmTopic`) se conserva por compatibilidad del contrato de eventos del
 * outbox (`notifications.notification_queued` lleva `fcm_topic`); el valor ya NO es un topic de Firebase,
 * es el nombre de topic de ntfy/UnifiedPush y del web push. Sin Google.
 */
final readonly class FcmTopic
{
    private const PREFIX = 'shipment_';

    private function __construct(
        public string $value,
    ) {}

    /**
     * Construye el topic del envío a partir de su id.
     */
    public static function forShipment(string $shipmentId): self
    {
        $trimmed = trim($shipmentId);
        if ($trimmed === '') {
            throw NotificationsException::because('el id de envío para el topic del envío no puede estar vacío');
        }

        return new self(self::PREFIX.$trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
