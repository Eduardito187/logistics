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
use Logistics\Notifications\Infrastructure\Channel\NtfyChannelSender;

/**
 * Canal push por ntfy/UnifiedPush (open-source, sin Google). Verifica que publica al topic del envío
 * con un HTTP POST a {NTFY_URL}/{topic} y que, sin NTFY_URL, queda "no configurado" y lanza.
 *
 * Usa Http::fake (requiere el stack booteado); corre en los containers (DB logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

function ntfyNotification(): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'shp-ntfy-1',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::PushNtfy]),
    );
}

$ntfyConfig = ['url' => 'http://ntfy.test', 'token' => null, 'priority' => 'high', 'timeout' => 5];

it('publica el push al topic del envío en el servidor ntfy', function () use ($ntfyConfig): void {
    Http::fake(['*' => Http::response('{"id":"x"}', 200)]);

    $sender = new NtfyChannelSender($ntfyConfig);
    $sender->send(ntfyNotification(), Channel::PushNtfy, RenderedMessage::of('En camino', 'Tu pedido va en camino.'));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://ntfy.test/shipment_shp-ntfy-1'
            && $request->body() === 'Tu pedido va en camino.'
            && $request->hasHeader('Title', 'En camino')
            && $request->hasHeader('Priority', 'high');
    });
});

it('lanza cuando ntfy no está configurado (sin NTFY_URL)', function (): void {
    Http::fake();

    $sender = new NtfyChannelSender(['url' => null, 'token' => null, 'priority' => 'default', 'timeout' => 5]);

    expect(fn () => $sender->send(ntfyNotification(), Channel::PushNtfy, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'no está configurado');

    Http::assertNothingSent();
});

it('lanza cuando ntfy rechaza la publicación', function () use ($ntfyConfig): void {
    Http::fake(['*' => Http::response('nope', 503)]);

    $sender = new NtfyChannelSender($ntfyConfig);

    expect(fn () => $sender->send(ntfyNotification(), Channel::PushNtfy, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'ntfy rechazó');
});
