<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Channel\Fcm\FcmAccessTokenProvider;
use Logistics\Notifications\Infrastructure\Image\PushImageResolver;

/**
 * Canal push de la app de clientes: publica en el topic FCM del envío (`shipment_<id>`) vía la API
 * FCM HTTP v1 (ADR 006 / P1-NOT-02). La plataforma publica al topic sin conocer tokens de dispositivos.
 *
 * Adjunta, cuando se puede, un collage de las imágenes de los productos del pedido (campo `image` de la
 * notificación FCM). Si el collage no se pudo armar (sin GD, sin imágenes), el push sale sin imagen.
 *
 * Corre en el relay del outbox (asíncrono, idempotente): nunca en un request de usuario. Si FCM no está
 * configurado o la publicación falla, lanza: el relay aplica backoff y, según la política, escala.
 */
final readonly class FcmChannelSender implements ChannelSender
{
    /**
     * @param array{project_id: ?string, endpoint: string, timeout: int} $config
     */
    public function __construct(
        private FcmAccessTokenProvider $tokens,
        private array $config,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $projectId = $this->config['project_id'] ?? null;
        $token = $this->tokens->token();
        if ($projectId === null || $projectId === '' || $token === null) {
            throw NotificationsException::because('el canal FCM no está configurado (falta project_id o credenciales)');
        }

        $url = str_replace('{project}', $projectId, $this->config['endpoint']);

        // Collage de productos (hasta 4, con "+N" si hay más). Null = push sin imagen.
        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());

        $notificationPayload = [
            'title' => $message->subject ?? $notification->messageType()->value,
            'body' => $message->body,
        ];
        if ($imageUrl !== null) {
            $notificationPayload['image'] = $imageUrl;
        }

        $response = Http::withToken($token)
            ->timeout($this->config['timeout'])
            ->post($url, [
                'message' => [
                    'topic' => $notification->fcmTopic()->value,
                    'notification' => $notificationPayload,
                    'data' => [
                        'shipment_id' => $notification->shipmentId(),
                        'message_type' => $notification->messageType()->value,
                        'notification_id' => $notification->id()->toString(),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "FCM rechazó la publicación al topic {$notification->fcmTopic()->value}: HTTP {$response->status()}",
            );
        }
    }
}
