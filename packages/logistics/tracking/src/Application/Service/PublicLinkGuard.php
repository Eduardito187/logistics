<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Service;

use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Logistics\Tracking\Domain\Model\ShipmentTracking;

/**
 * Aplica el vencimiento del enlace público (`tracking.public_link_ttl_hours`) a un seguimiento.
 *
 * Regla (vive en el dominio, ver ShipmentTracking::publicLinkExpiresAt()): el TTL cuenta DESDE LA ENTREGA;
 * un pedido no entregado nunca vence por TTL. Lo usan todas las consultas/comandos públicos por token
 * (página /t/{token}, API de canal, productos, encuesta) para no servir datos de un enlace vencido.
 */
final readonly class PublicLinkGuard
{
    public function __construct(
        private PublicLinkTtl $ttl,
        private Clock $clock,
    ) {}

    public function isExpired(ShipmentTracking $tracking): bool
    {
        return $tracking->isPublicLinkExpiredAt($this->clock->now(), $this->ttl->hours());
    }
}
