<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Fcm\FcmAccessToken;
use Logistics\Notifications\Infrastructure\Image\PushImageResolver;

/**
 * Canal push de la app de clientes sobre **Firebase Cloud Messaging (FCM)**, API HTTP v1. Publica un
 * mensaje al topic del envío (`shipment_<id>`) con un POST a
 * `https://fcm.googleapis.com/v1/projects/{PROJECT_ID}/messages:send`; la app Android/iOS está suscrita
 * a ese topic y recibe el push. La plataforma publica al topic SIN conocer tokens de dispositivo (se
 * conserva el modelo de topic del canal push anterior; solo cambia el transporte a Google/FCM).
 *
 * El access token OAuth2 del service account lo resuelve FcmAccessToken (sin SDK de Google). Si se puede
 * armar el collage de productos, lo adjunta en `notification.image` y en `data`; si no, el push sale sin
 * imagen.
 *
 * Corre en el relay del outbox (asíncrono, idempotente): nunca en un request de usuario. Si FCM no está
 * configurado (sin project_id o credenciales) o la publicación falla, lanza: el relay aplica backoff y,
 * según la política, escala al canal siguiente.
 *
 * Además de publicar al topic ({@see self::send()}), expone {@see self::sendToDevice()} para publicar a
 * UN token de dispositivo (FID), con el mismo payload; útil para notificaciones internas dirigidas.
 */
final readonly class FcmChannelSender implements ChannelSender
{
    /**
     * @param array{project_id: ?string, timeout: int} $config
     */
    public function __construct(
        private array $config,
        private FcmAccessToken $accessToken,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        // Un topic por envío: aísla a cada cliente a las novedades de su propio pedido (`shipment_<id>`).
        $topic = $notification->fcmTopic()->value;

        $message = $this->buildMessage($notification, $message);
        $message['topic'] = $topic;

        $this->publish($message, "al topic {$topic}");
    }

    /**
     * Envío directo a UN dispositivo por su token FCM (Firebase Installation ID), en vez de al topic del
     * envío. Misma forma de payload (`notification` + `data`), solo cambia el destino: `token` por
     * `topic`. Pensado para notificaciones internas dirigidas a un usuario/equipo concreto; ver la nota
     * de alcance en el reporte: hoy la Notification de dominio no lleva destinatario de tipo dispositivo,
     * así que este método expone la CAPACIDAD de envío por token sin cablear el flujo dirigido.
     *
     * Corre en el relay del outbox (asíncrono, idempotente), nunca en un request de usuario. Si FCM no
     * está configurado o la publicación falla, lanza: el relay aplica backoff.
     */
    public function sendToDevice(Notification $notification, string $pushToken, RenderedMessage $message): void
    {
        $message = $this->buildMessage($notification, $message);
        $message['token'] = $pushToken;

        $this->publish($message, 'al dispositivo');
    }

    /**
     * Arma el bloque `message` común (notification + data + android) sin el destino (topic/token).
     *
     * @return array{notification: array{title: string, body: string, image?: string}, data: array<string, string>, android: array{priority: string}}
     */
    private function buildMessage(Notification $notification, RenderedMessage $message): array
    {
        $title = $message->subject ?? $notification->messageType()->value;

        /** @var array<string, string> $data */
        $data = [
            'shipment_id' => $notification->shipmentId(),
            'message_type' => $notification->messageType()->value,
            'notification_id' => $notification->id()->toString(),
        ];

        /** @var array{title: string, body: string, image?: string} $notificationPayload */
        $notificationPayload = [
            'title' => $title,
            'body' => $message->body,
        ];

        // Collage de productos (hasta 4, con "+N" si hay más). Null = push sin imagen.
        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());
        if ($imageUrl !== null) {
            $notificationPayload['image'] = $imageUrl;
            $data['image'] = $imageUrl;
        }

        return [
            'notification' => $notificationPayload,
            'data' => $data,
            'android' => [
                'priority' => 'high',
            ],
        ];
    }

    /**
     * Publica un bloque `message` (ya con su destino topic/token) por la API HTTP v1 de FCM.
     *
     * @param array<string, mixed> $message
     */
    private function publish(array $message, string $destinationLabel): void
    {
        $projectId = $this->config['project_id'] ?? null;
        if ($projectId === null || trim($projectId) === '') {
            throw NotificationsException::because('el canal push (FCM) no está configurado (falta FCM_PROJECT_ID)');
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $response = Http::withToken($this->accessToken->value())
            ->timeout($this->config['timeout'])
            // B-2: tope del handshake de conexión; un connect colgado no debe gastar el timeout total.
            ->connectTimeout(3)
            ->post($endpoint, ['message' => $message]);

        if ($response->failed()) {
            throw NotificationsException::because(
                "FCM rechazó la publicación {$destinationLabel}: HTTP {$response->status()}",
            );
        }
    }
}
