<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Image\PushImageResolver;

/**
 * Canal Telegram sobre la Bot API (`sendMessage` / `sendPhoto`). El destinatario se resuelve por su
 * chat_id (lo captura el envío: lo deja el cliente al vincular el bot con su pedido).
 *
 * Cuando hay collage de productos, usa `sendPhoto` con el cuerpo como caption; si no, `sendMessage`.
 * Si el bot no está configurado o no hay chat_id, lanza para que el relay escale. Corre en el relay del
 * outbox (asíncrono, idempotente), nunca en un request de usuario.
 */
final readonly class TelegramChannelSender implements ChannelSender
{
    /**
     * @param array{endpoint: string, bot_token: ?string, timeout: int} $config
     */
    public function __construct(
        private array $config,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $chatId = $notification->telegramChatId();
        if ($chatId === null) {
            throw NotificationsException::because('no hay chat_id de Telegram para esta notificación');
        }

        $botToken = $this->config['bot_token'] ?? null;
        if ($botToken === null || $botToken === '') {
            throw NotificationsException::because('el canal Telegram no está configurado (falta bot_token)');
        }

        $base = rtrim($this->config['endpoint'], '/').'/bot'.$botToken;

        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());
        [$method, $payload] = $imageUrl !== null
            ? ['sendPhoto', ['chat_id' => $chatId, 'photo' => $imageUrl, 'caption' => $message->body]]
            : ['sendMessage', ['chat_id' => $chatId, 'text' => $message->body]];

        $response = Http::timeout($this->config['timeout'])
            // B-2: tope del handshake de conexión, separado del timeout total.
            ->connectTimeout(3)
            ->post($base.'/'.$method, $payload);

        if ($response->failed()) {
            throw NotificationsException::because(
                "Telegram Bot API rechazó el envío al chat {$chatId}: HTTP {$response->status()}",
            );
        }
    }
}
