<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Channel\WebPushChannelSender;

/**
 * Canal web push por VAPID (open-source), entregado por el servidor ntfy. Verifica que publica al topic
 * del envío marcándolo como web y que, sin NTFY_URL o sin clave pública VAPID, queda "no configurado".
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

function webPushNotification(): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'shp-web-1',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::WebPush]),
    );
}

$webPushConfig = ['url' => 'http://ntfy.test', 'token' => null, 'vapid_public_key' => 'BPublicKeyVapid', 'timeout' => 5];

it('publica el web push al topic del envío vía ntfy', function () use ($webPushConfig): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $sender = new WebPushChannelSender($webPushConfig);
    $sender->send(webPushNotification(), Channel::WebPush, RenderedMessage::of('En camino', 'Tu pedido va en camino.'));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://ntfy.test/shipment_shp-web-1'
            && $request->hasHeader('X-Web-Push', '1')
            && $request->body() === 'Tu pedido va en camino.';
    });
});

it('lanza cuando falta NTFY_URL', function (): void {
    Http::fake();

    $sender = new WebPushChannelSender(['url' => null, 'token' => null, 'vapid_public_key' => 'BKey', 'timeout' => 5]);

    expect(fn () => $sender->send(webPushNotification(), Channel::WebPush, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'no está configurado');

    Http::assertNothingSent();
});

it('lanza cuando falta la clave pública VAPID', function (): void {
    Http::fake();

    $sender = new WebPushChannelSender(['url' => 'http://ntfy.test', 'token' => null, 'vapid_public_key' => null, 'timeout' => 5]);

    expect(fn () => $sender->send(webPushNotification(), Channel::WebPush, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'VAPID');

    Http::assertNothingSent();
});
