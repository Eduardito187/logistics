<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Topic por envío (`shipment_<id>`). La app de clientes (push por Firebase Cloud Messaging) se suscribe
 * a él; la plataforma publica al topic SIN conocer tokens ni endpoints de los dispositivos (ADR 006). Un
 * topic por envío aísla a cada cliente: solo recibe las novedades de su propio pedido.
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
