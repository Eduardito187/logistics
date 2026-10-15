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
 * Canal push de la app de clientes sobre **ntfy** self-host (compatible con UnifiedPush): reemplaza al
 * push de Firebase/FCM. Publica un mensaje al topic del envío (`shipment_<id>`) con un simple HTTP POST
 * a `{NTFY_URL}/{topic}`; la app Android/iOS (o cualquier distribuidor UnifiedPush) está suscrita a ese
 * topic y recibe el push. La plataforma publica al topic SIN conocer tokens de dispositivos, igual que
 * antes: solo cambia el transporte (ntfy en vez de Google).
 *
 * Todo es open-source y gratis: el servidor ntfy corre en el docker-compose (`http://ntfy:80`). Si se
 * puede armar el collage de productos, lo adjunta vía las cabeceras `Attach`/`Icon` de ntfy; si no, el
 * push sale sin imagen.
 *
 * Corre en el relay del outbox (asíncrono, idempotente): nunca en un request de usuario. Si ntfy no
 * está configurado (sin `NTFY_URL`) o la publicación falla, lanza: el relay aplica backoff y, según la
 * política, escala al canal siguiente.
 */
final readonly class NtfyChannelSender implements ChannelSender
{
    /**
     * @param array{url: ?string, token: ?string, priority: string, timeout: int} $config
     */
    public function __construct(
        private array $config,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $url = $this->config['url'] ?? null;
        if ($url === null || trim($url) === '') {
            throw NotificationsException::because('el canal push (ntfy) no está configurado (falta NTFY_URL)');
        }

        // Un topic por envío: aísla a cada cliente a las novedades de su propio pedido (mismo esquema
        // `shipment_<id>` que usaba el topic de FCM; ntfy usa el nombre del topic tal cual).
        $topic = $notification->fcmTopic()->value;
        $endpoint = rtrim($url, '/').'/'.$topic;

        // ntfy toma título/prioridad/etiquetas por cabeceras (UTF-8 seguro vía RFC 2047 no hace falta en
        // dev; en producción conviene topics y títulos ASCII). El cuerpo del POST es el texto del push.
        $headers = [
            'Title' => $message->subject ?? $notification->messageType()->value,
            'Priority' => $this->config['priority'],
            'Tags' => 'package',
            'X-Shipment-Id' => $notification->shipmentId(),
            'X-Message-Type' => $notification->messageType()->value,
            'X-Notification-Id' => $notification->id()->toString(),
        ];

        // Collage de productos (hasta 4, con "+N" si hay más). Null = push sin imagen.
        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());
        if ($imageUrl !== null) {
            $headers['Attach'] = $imageUrl;
            $headers['Icon'] = $imageUrl;
        }

        $request = Http::withHeaders($headers)->timeout($this->config['timeout']);

        // Token de acceso de ntfy (si el servidor exige auth para publicar). Opcional en self-host abierto.
        $token = $this->config['token'] ?? null;
        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->withBody($message->body, 'text/plain')->post($endpoint);

        if ($response->failed()) {
            throw NotificationsException::because(
                "ntfy rechazó la publicación al topic {$topic}: HTTP {$response->status()}",
            );
        }
    }
}
