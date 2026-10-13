<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Event\NotificationQueued;
use Logistics\Notifications\Domain\Event\NotificationSent;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;

/**
 * Encola una notificación de ejemplo con la cascada completa del ADR 006.
 */
function queueNotification(?CascadePolicy $policy = null): Notification
{
    return Notification::queue(
        NotificationId::generate(),
        'f8c3de3d-1fea-4d7c-a8b0-29f63c4c3454',
        MessageType::EnRoute,
        $policy ?? CascadePolicy::of([Channel::PushFcm, Channel::WebPush, Channel::Email, Channel::Sms]),
    );
}

it('queues a notification in Queued status pointing at the first channel', function (): void {
    $notification = queueNotification();

    expect($notification->status())->toBe(NotificationStatus::Queued)
        ->and($notification->currentChannel())->toBe(Channel::PushFcm)
        ->and($notification->attempts())->toBe([])
        ->and($notification->fcmTopic()->value)->toBe('shipment_f8c3de3d-1fea-4d7c-a8b0-29f63c4c3454');
});

it('records a NotificationQueued event with the cascade channels', function (): void {
    $notification = queueNotification();

    $events = $notification->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NotificationQueued::class);

    /** @var NotificationQueued $event */
    $event = $events[0];
    expect($event->eventName())->toBe('notifications.notification_queued')
        ->and($event->firstChannel)->toBe('PUSH_FCM')
        ->and($event->channels)->toBe(['PUSH_FCM', 'WEB_PUSH', 'EMAIL', 'SMS'])
        ->and($event->payload()['fcm_topic'])->toBe('shipment_f8c3de3d-1fea-4d7c-a8b0-29f63c4c3454');
});

it('marks sent via a channel, counting the attempt and recording the event', function (): void {
    $notification = queueNotification();
    $notification->releaseEvents();

    $notification->markSentVia(Channel::WebPush);

    expect($notification->status())->toBe(NotificationStatus::Sent)
        ->and($notification->currentChannel())->toBe(Channel::WebPush)
        ->and($notification->attempts())->toBe(['WEB_PUSH' => 1]);

    $events = $notification->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NotificationSent::class);

    /** @var NotificationSent $event */
    $event = $events[0];
    expect($event->eventName())->toBe('notifications.notification_sent')
        ->and($event->channel)->toBe('WEB_PUSH');
});

it('rejects marking sent via a channel outside the policy', function (): void {
    $notification = queueNotification(CascadePolicy::of([Channel::PushFcm, Channel::Email]));

    expect(fn () => $notification->markSentVia(Channel::Sms))
        ->toThrow(NotificationsException::class, 'no pertenece a la política');
});

it('escalate walks the full cascade in order and then fails', function (): void {
    $notification = queueNotification();
    $notification->releaseEvents();

    expect($notification->currentChannel())->toBe(Channel::PushFcm);

    $notification->escalate();
    expect($notification->currentChannel())->toBe(Channel::WebPush);

    $notification->escalate();
    expect($notification->currentChannel())->toBe(Channel::Email);

    $notification->escalate();
    expect($notification->currentChannel())->toBe(Channel::Sms)
        ->and($notification->status())->toBe(NotificationStatus::Queued);

    // Agota la cascada: no hay canal después de SMS.
    $notification->escalate();
    expect($notification->status())->toBe(NotificationStatus::Failed)
        ->and($notification->attempts())->toBe([
            'PUSH_FCM' => 1,
            'WEB_PUSH' => 1,
            'EMAIL' => 1,
            'SMS' => 1,
        ]);
});

it('does not escalate a notification that is not queued', function (): void {
    $notification = queueNotification();
    $notification->markSentVia(Channel::PushFcm);

    expect(fn () => $notification->escalate())
        ->toThrow(NotificationsException::class, 'solo se escala');
});

it('suppresses a queued notification', function (): void {
    $notification = queueNotification();

    $notification->suppress();

    expect($notification->status())->toBe(NotificationStatus::Suppressed);
});

it('rejects suppressing an already sent notification', function (): void {
    $notification = queueNotification();
    $notification->markSentVia(Channel::PushFcm);

    expect(fn () => $notification->suppress())
        ->toThrow(NotificationsException::class, 'ya enviada');
});

it('rejects sending a suppressed notification', function (): void {
    $notification = queueNotification();
    $notification->suppress();

    expect(fn () => $notification->markSentVia(Channel::PushFcm))
        ->toThrow(NotificationsException::class, 'suprimida');
});
