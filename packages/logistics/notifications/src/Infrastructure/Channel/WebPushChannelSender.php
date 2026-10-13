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
 * Canal web push de la página de tracking (P1-NOT-03): publica en el MISMO topic FCM del envío, pero
 * con la sección `webpush` del mensaje FCM HTTP v1. El navegador del cliente se suscribe al topic desde
 * la página de tracking, así que la plataforma tampoco conoce su token.
 *
 * Adjunta el collage de productos (campo `image` de la notificación web push) cuando se puede armar; si
 * no, sale sin imagen. Corre en el relay del outbox. Si FCM no está configurado o la publicación falla,
 * lanza.
 */
final readonly class WebPushChannelSender implements ChannelSender
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
            throw NotificationsException::because('el canal web push no está configurado (falta project_id o credenciales)');
        }

        $url = str_replace('{project}', $projectId, $this->config['endpoint']);

        // Collage de productos (hasta 4, con "+N" si hay más). Null = web push sin imagen.
        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());

        $webpushNotification = [
            'title' => $message->subject ?? $notification->messageType()->value,
            'body' => $message->body,
        ];
        if ($imageUrl !== null) {
            $webpushNotification['image'] = $imageUrl;
        }

        $response = Http::withToken($token)
            ->timeout($this->config['timeout'])
            ->post($url, [
                'message' => [
                    'topic' => $notification->fcmTopic()->value,
                    'webpush' => [
                        'notification' => $webpushNotification,
                    ],
                    'data' => [
                        'shipment_id' => $notification->shipmentId(),
                        'message_type' => $notification->messageType()->value,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "FCM rechazó el web push al topic {$notification->fcmTopic()->value}: HTTP {$response->status()}",
            );
        }
    }
}
