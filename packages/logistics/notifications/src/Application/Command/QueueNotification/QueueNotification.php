<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\QueueNotification;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para encolar una notificación de un envío. El tipo de mensaje determina la cascada de
 * canales (se resuelve desde las reglas configuradas). Email, teléfono (SMS/WhatsApp) y chat id de
 * Telegram son contactos de respaldo opcionales; el canal primario va por el topic FCM del envío, sin
 * datos de contacto.
 */
final readonly class QueueNotification implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $messageType,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $telegramChatId = null,
    ) {}
}
