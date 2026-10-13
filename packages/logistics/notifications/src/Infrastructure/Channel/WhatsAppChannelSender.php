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
 * Canal WhatsApp sobre la Meta WhatsApp Cloud API (Graph API `/{phone_id}/messages`).
 *
 * NOTA (decisión del dueño, 2026-10): el ADR 006 original excluía WhatsApp por costo. El dueño del
 * producto pidió habilitarlo; se agrega como un canal más de la cascada. El detalle del cambio queda
 * en el README del package (no se tocan los ADR desde este contexto).
 *
 * Usa el teléfono de respaldo del destinatario como número de WhatsApp (formato E.164 sin "+"). Cuando
 * hay un collage de productos, manda un mensaje de imagen con el cuerpo como caption; si no, texto. Si
 * el canal no está configurado o no hay teléfono, lanza para que el relay escale. Corre en el relay del
 * outbox (asíncrono, idempotente), nunca en un request de usuario.
 */
final readonly class WhatsAppChannelSender implements ChannelSender
{
    /**
     * @param array{endpoint: string, token: ?string, phone_id: ?string, api_version: string, timeout: int} $config
     */
    public function __construct(
        private array $config,
        private ?PushImageResolver $images = null,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $phone = $notification->phone();
        if ($phone === null) {
            throw NotificationsException::because('no hay teléfono de WhatsApp para esta notificación');
        }

        $token = $this->config['token'] ?? null;
        $phoneId = $this->config['phone_id'] ?? null;
        if ($token === null || $token === '' || $phoneId === null || $phoneId === '') {
            throw NotificationsException::because('el canal WhatsApp no está configurado (falta token o phone_id)');
        }

        $url = rtrim($this->config['endpoint'], '/').'/'.$this->config['api_version'].'/'.$phoneId.'/messages';
        $to = ltrim($phone, '+');

        $imageUrl = $this->images?->imageUrlForShipment($notification->shipmentId());
        $payload = $imageUrl !== null
            ? $this->imagePayload($to, $imageUrl, $message->body)
            : $this->textPayload($to, $message->body);

        $response = Http::withToken($token)
            ->timeout($this->config['timeout'])
            ->post($url, $payload);

        if ($response->failed()) {
            throw NotificationsException::because(
                "WhatsApp Cloud API rechazó el envío a {$to}: HTTP {$response->status()}",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $to, string $body): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imagePayload(string $to, string $imageUrl, string $caption): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            'image' => [
                'link' => $imageUrl,
                'caption' => $caption,
            ],
        ];
    }
}
