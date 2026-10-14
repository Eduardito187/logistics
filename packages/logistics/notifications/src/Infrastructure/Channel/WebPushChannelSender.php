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
 * Canal web push de la página de tracking (P1-NOT-03) sobre el protocolo estándar **Web Push (VAPID)**,
 * 100% open-source y sin Google. La entrega la hace el servidor ntfy self-host, que soporta Web Push de
 * forma nativa: se configura con un par de claves VAPID (`web-push-public-key`/`web-push-private-key`) y
 * el navegador del cliente se suscribe al topic del envío desde la página de tracking usando la clave
 * pública VAPID. La plataforma solo publica al topic (`shipment_<id>`); ntfy firma y entrega el web push
 * a cada navegador suscrito con su propia clave VAPID, así que la plataforma tampoco conoce su endpoint.
 *
 * A diferencia del push de la app (ntfy/UnifiedPush), este canal marca el mensaje como web (`X-Web-Push`)
 * para que el service worker de la página de tracking lo distinga. Adjunta el collage de productos cuando
 * se puede armar. Corre en el relay del outbox. Si ntfy no está configurado (sin `NTFY_URL`) o no hay
 * clave pública VAPID (el web push no estaría operativo), lanza para que el relay escale.
 */
final readonly class WebPushChannelSender implements ChannelSender
{
    /**
     * @param array{url: ?string, token: ?string, vapid_public_key: ?string, timeout: int} $config
     */
    public function __construct(
        private array $config,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $url = $this->config['url'] ?? null;
        if ($url === null || trim($url) === '') {
            throw NotificationsException::because('el canal web push no está configurado (falta NTFY_URL)');
        }

        $vapidPublicKey = $this->config['vapid_public_key'] ?? null;
        if ($vapidPublicKey === null || trim($vapidPublicKey) === '') {
            throw NotificationsException::because('el canal web push no está configurado (falta la clave pública VAPID)');
        }

        // Mismo topic por envío que el push de la app; ntfy lo entrega a los navegadores suscritos.
        $topic = $notification->fcmTopic()->value;
        $endpoint = rtrim($url, '/').'/'.$topic;

        $headers = [
            'Title' => $message->subject ?? $notification->messageType()->value,
            'Tags' => 'package',
            'X-Web-Push' => '1',
            'X-Shipment-Id' => $notification->shipmentId(),
            'X-Message-Type' => $notification->messageType()->value,
        ];

        // Collage de productos (hasta 4, con "+N" si hay más). Null = web push sin imagen.
        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());
        if ($imageUrl !== null) {
            $headers['Attach'] = $imageUrl;
            $headers['Icon'] = $imageUrl;
        }

        $request = Http::withHeaders($headers)->timeout($this->config['timeout']);

        $token = $this->config['token'] ?? null;
        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->withBody($message->body, 'text/plain')->post($endpoint);

        if ($response->failed()) {
            throw NotificationsException::because(
                "ntfy rechazó el web push al topic {$topic}: HTTP {$response->status()}",
            );
        }
    }
}
