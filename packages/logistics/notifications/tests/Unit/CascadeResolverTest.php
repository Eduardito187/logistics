<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\Service\CascadeResolver;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;

/**
 * Notificación de ejemplo con la cascada completa del ADR 006.
 */
function resolverNotification(): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'shp-1',
        MessageType::ConfirmLocation,
        CascadePolicy::of([Channel::Fcm, Channel::Telegram, Channel::Email, Channel::Sms]),
    );
}

it('resolves the next channel in order Fcm -> Telegram -> Email -> Sms', function (): void {
    $resolver = new CascadeResolver;
    $notification = resolverNotification();

    // Fcm -> Telegram
    expect($resolver->nextChannel($notification))->toBe(Channel::Telegram)
        ->and($resolver->canEscalate($notification))->toBeTrue();

    $notification->escalate();
    // Telegram -> Email
    expect($resolver->nextChannel($notification))->toBe(Channel::Email);

    $notification->escalate();
    // Email -> Sms
    expect($resolver->nextChannel($notification))->toBe(Channel::Sms);

    $notification->escalate();
    // Sms es el último: no hay siguiente.
    expect($resolver->nextChannel($notification))->toBeNull()
        ->and($resolver->canEscalate($notification))->toBeFalse();
});

it('honors a custom policy order', function (): void {
    $resolver = new CascadeResolver;
    $notification = Notification::queue(
        NotificationId::generate(),
        'shp-2',
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Email, Channel::Sms]),
    );

    expect($resolver->nextChannel($notification))->toBe(Channel::Sms);
});
