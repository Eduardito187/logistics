<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;

/**
 * Contrato de un canal de entrega concreto (push ntfy, web push, Telegram, email, SMS).
 *
 * La implementación real corre en el relay del outbox (envío asíncrono, idempotente): nunca dentro de
 * un request de usuario. El dominio solo declara la forma del contrato; las implementaciones viven en
 * Infrastructure o en el conector del canal.
 */
interface ChannelSender
{
    /**
     * Envía la notificación por el canal indicado con el mensaje ya renderizado (asunto + cuerpo). Si
     * el envío falla, DEBE lanzar: el relay captura la excepción, aplica backoff y, según la política,
     * escala al canal siguiente.
     */
    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void;
}
