<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Domain\ValueObject\SmsBudget;
use Logistics\Notifications\Infrastructure\Channel\SmsChannelSender;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Canal SMS por gateway open-source (android-sms-gateway / Jasmin / Kannel), vía SMS_GATEWAY_URL. No un
 * SaaS de pago. Verifica el payload (`message` + `phoneNumbers`), el Basic auth, el tope mensual y el
 * modo "no configurado" sin SMS_GATEWAY_URL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Presupuesto en memoria: `consume()` devuelve el valor fijado (hay cupo o no).
 */
function smsBudgetRepo(bool $hasRoom): SmsBudgetRepository
{
    return new class($hasRoom) implements SmsBudgetRepository
    {
        public function __construct(private bool $hasRoom) {}

        public function current(string $period, int $cap): SmsBudget
        {
            return SmsBudget::of($period, 0, $cap);
        }

        public function consume(string $period, int $cap): bool
        {
            return $this->hasRoom;
        }
    };
}

function smsClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T12:00:00+00:00');
        }
    };
}

function smsNotification(?string $phone = '+59170000000'): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'shp-sms-1',
        MessageType::ConfirmLocation,
        CascadePolicy::of([Channel::Sms]),
        null,
        $phone,
    );
}

$gatewayConfig = [
    'url' => 'http://sms-gateway.test/message',
    'token' => null,
    'username' => 'dismac',
    'password' => 'secret',
    'sender' => 'Dismac',
    'monthly_cap' => 2000,
    'timeout' => 5,
];

it('envía por el gateway open-source con el payload y el Basic auth esperados', function () use ($gatewayConfig): void {
    Http::fake(['*' => Http::response('{"state":"Pending"}', 200)]);

    $sender = new SmsChannelSender(smsBudgetRepo(true), smsClock(), $gatewayConfig);
    $sender->send(smsNotification(), Channel::Sms, RenderedMessage::of(null, 'Confirma tu ubicación.'));

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'http://sms-gateway.test/message'
            && $request->hasHeader('Authorization')
            && str_starts_with((string) $request->header('Authorization')[0], 'Basic ')
            && $data['message'] === 'Confirma tu ubicación.'
            && $data['phoneNumbers'] === ['+59170000000'];
    });
});

it('lanza cuando el gateway no está configurado (sin SMS_GATEWAY_URL)', function () use ($gatewayConfig): void {
    Http::fake();

    $sender = new SmsChannelSender(smsBudgetRepo(true), smsClock(), [...$gatewayConfig, 'url' => null]);

    expect(fn () => $sender->send(smsNotification(), Channel::Sms, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'no está configurado');

    Http::assertNothingSent();
});

it('lanza cuando no hay teléfono de respaldo', function () use ($gatewayConfig): void {
    Http::fake();

    $sender = new SmsChannelSender(smsBudgetRepo(true), smsClock(), $gatewayConfig);

    expect(fn () => $sender->send(smsNotification(null), Channel::Sms, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'teléfono');

    Http::assertNothingSent();
});

it('lanza cuando se alcanzó el tope mensual', function () use ($gatewayConfig): void {
    Http::fake();

    $sender = new SmsChannelSender(smsBudgetRepo(false), smsClock(), $gatewayConfig);

    expect(fn () => $sender->send(smsNotification(), Channel::Sms, RenderedMessage::of(null, 'hola')))
        ->toThrow(NotificationsException::class, 'tope mensual');

    Http::assertNothingSent();
});
