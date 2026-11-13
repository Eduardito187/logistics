<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\Channel;

it('lee los alias históricos de push y los traduce al canal vigente', function (): void {
    expect(Channel::fromStored('PUSH_FCM'))->toBe(Channel::Fcm)
        ->and(Channel::fromStored('PUSH_NTFY'))->toBe(Channel::Fcm);
});

it('normaliza mayúsculas y espacios al leer un valor persistido', function (): void {
    expect(Channel::fromStored('  fcm  '))->toBe(Channel::Fcm)
        ->and(Channel::fromStored('email'))->toBe(Channel::Email);
});

it('devuelve null para un valor persistido que ya no corresponde a ningún canal', function (): void {
    expect(Channel::fromStored('WEB_PUSH'))->toBeNull()
        ->and(Channel::fromStored('WHATSAPP'))->toBeNull()
        ->and(Channel::fromStored(''))->toBeNull();
});

it('indica qué canales admiten imagen', function (): void {
    expect(Channel::Fcm->supportsImage())->toBeTrue()
        ->and(Channel::Telegram->supportsImage())->toBeTrue()
        ->and(Channel::Email->supportsImage())->toBeFalse()
        ->and(Channel::Sms->supportsImage())->toBeFalse();
});
