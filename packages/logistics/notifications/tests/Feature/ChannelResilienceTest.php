<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Domain\ValueObject\SmsBudget;
use Logistics\Notifications\Infrastructure\Channel\RoutingChannelSender;
use Logistics\Notifications\Infrastructure\Channel\SmsChannelSender;
use Logistics\Notifications\Infrastructure\Channel\TelegramChannelSender;
use Logistics\Outbox\Infrastructure\Resilience\CircuitBreaker;
use Logistics\Outbox\Infrastructure\Resilience\CircuitOpenException;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Resiliencia de los canales de notificación: connectTimeout (B-2) en Telegram y SMS, y circuit breaker
 * POR CANAL (A-5) en el RoutingChannelSender: un canal caído se aísla y corta rápido sin arrastrar a los
 * sanos. Http::fake captura las opciones de Guzzle (connect_timeout).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

function resClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-20T12:00:00+00:00');
        }
    };
}

function resSmsBudget(): SmsBudgetRepository
{
    return new class implements SmsBudgetRepository
    {
        public function current(string $period, int $cap): SmsBudget
        {
            return SmsBudget::of($period, 0, $cap);
        }

        public function consume(string $period, int $cap): bool
        {
            return true;
        }
    };
}

it('Telegram fija connectTimeout además del timeout total (B-2)', function (): void {
    $captured = [];
    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(['ok' => true], 200);
    });

    $sender = new TelegramChannelSender(['endpoint' => 'https://api.telegram.org', 'bot_token' => 'bot-123', 'timeout' => 5]);
    $notification = Notification::queue(
        NotificationId::generate(),
        'shp-tg-1',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Telegram]),
        null,
        null,
        '99887766',
    );

    $sender->send($notification, Channel::Telegram, RenderedMessage::of('En camino', 'Tu pedido va en camino.'));

    expect($captured['connect_timeout'] ?? null)->toBe(3)
        ->and($captured['timeout'] ?? null)->toBe(5);
});

it('SMS fija connectTimeout además del timeout total (B-2)', function (): void {
    $captured = [];
    Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(['ok' => true], 200);
    });

    $sender = new SmsChannelSender(
        resSmsBudget(),
        resClock(),
        ['url' => 'https://sms.example.test/send', 'token' => null, 'username' => 'u', 'password' => 'p', 'sender' => 'DISMAC', 'monthly_cap' => 1000, 'timeout' => 5],
    );
    $notification = Notification::queue(
        NotificationId::generate(),
        'shp-sms-1',
        MessageType::ConfirmLocation,
        CascadePolicy::of([Channel::Sms]),
        null,
        '+59170000000',
    );

    $sender->send($notification, Channel::Sms, RenderedMessage::of('Confirma', 'Confirma tu ubicación.'));

    expect($captured['connect_timeout'] ?? null)->toBe(3)
        ->and($captured['timeout'] ?? null)->toBe(5);
});

it('aísla un canal caído con un breaker por canal y corta rápido (A-5)', function (): void {
    // Sender falso que cuenta envíos y siempre falla (canal caído).
    $failing = new class implements ChannelSender
    {
        public int $calls = 0;

        public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
        {
            $this->calls++;

            throw new RuntimeException('canal caído');
        }
    };

    // Breaker con umbral 2 para el test (enfriamiento 30s, ventana 60s).
    $breaker = new CircuitBreaker(new CacheRepository(new ArrayStore), resClock(), 2, 30, 60, 'test-chan');
    $router = new RoutingChannelSender([Channel::Fcm->value => $failing], $breaker);

    $notification = Notification::queue(
        NotificationId::generate(),
        'shp-brk-1',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Fcm]),
    );
    $message = RenderedMessage::of('t', 'cuerpo');

    // Dos fallos abren el circuito del canal fcm.
    for ($i = 0; $i < 2; $i++) {
        expect(fn () => $router->send($notification, Channel::Fcm, $message))->toThrow(RuntimeException::class, 'canal caído');
    }
    expect($failing->calls)->toBe(2);

    // Circuito ABIERTO: el siguiente envío corta rápido, sin invocar al sender del canal.
    expect(fn () => $router->send($notification, Channel::Fcm, $message))->toThrow(CircuitOpenException::class);
    expect($failing->calls)->toBe(2);
});
