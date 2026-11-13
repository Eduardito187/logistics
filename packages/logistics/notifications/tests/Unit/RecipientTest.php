<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\Recipient;

it('construye un destinatario con solo el id de envío y recorta los valores', function (): void {
    $recipient = Recipient::of('  shp-1  ');

    expect($recipient->shipmentId)->toBe('shp-1')
        ->and($recipient->email)->toBeNull()
        ->and($recipient->phone)->toBeNull()
        ->and($recipient->telegramChatId)->toBeNull();
});

it('recorta y conserva los contactos de respaldo', function (): void {
    $recipient = Recipient::of('shp-1', '  ana@example.com  ', '  +59170000000  ', '  123456  ');

    expect($recipient->email)->toBe('ana@example.com')
        ->and($recipient->phone)->toBe('+59170000000')
        ->and($recipient->telegramChatId)->toBe('123456');
});

it('normaliza contactos vacíos a null', function (): void {
    $recipient = Recipient::of('shp-1', '   ', '   ', '   ');

    expect($recipient->email)->toBeNull()
        ->and($recipient->phone)->toBeNull()
        ->and($recipient->telegramChatId)->toBeNull();
});

it('rechaza un id de envío vacío', function (): void {
    expect(fn () => Recipient::of('   '))
        ->toThrow(NotificationsException::class, 'id de envío');
});

it('rechaza un email de respaldo sin arroba', function (): void {
    expect(fn () => Recipient::of('shp-1', 'no-es-email'))
        ->toThrow(NotificationsException::class, 'email de respaldo');
});

it('expone el topic FCM del envío', function (): void {
    expect(Recipient::of('shp-1')->fcmTopic()->value)->toBe('shipment_shp-1');
});

it('decide si puede recibir por cada canal según el contacto disponible', function (): void {
    $full = Recipient::of('shp-1', 'ana@example.com', '+59170000000', '123456');
    $bare = Recipient::of('shp-1');

    expect($full->canReceiveVia(Channel::Fcm))->toBeTrue()
        ->and($bare->canReceiveVia(Channel::Fcm))->toBeTrue()
        ->and($full->canReceiveVia(Channel::Email))->toBeTrue()
        ->and($bare->canReceiveVia(Channel::Email))->toBeFalse()
        ->and($full->canReceiveVia(Channel::Sms))->toBeTrue()
        ->and($bare->canReceiveVia(Channel::Sms))->toBeFalse()
        ->and($full->canReceiveVia(Channel::Telegram))->toBeTrue()
        ->and($bare->canReceiveVia(Channel::Telegram))->toBeFalse();
});
