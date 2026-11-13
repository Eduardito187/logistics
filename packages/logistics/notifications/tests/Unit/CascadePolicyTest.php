<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;

it('construye la política y expone el primer canal', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Email, Channel::Sms]);

    expect($policy->first())->toBe(Channel::Fcm)
        ->and($policy->escalateWhen)->toBe(EscalateCondition::NoOpen);
});

it('rechaza una política sin canales', function (): void {
    expect(fn () => CascadePolicy::of([]))
        ->toThrow(NotificationsException::class, 'al menos un canal');
});

it('rechaza un canal repetido en la política', function (): void {
    expect(fn () => CascadePolicy::of([Channel::Fcm, Channel::Fcm]))
        ->toThrow(NotificationsException::class, 'repetido');
});

it('devuelve el canal siguiente y null al agotar la cascada', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Email]);

    expect($policy->next(Channel::Fcm))->toBe(Channel::Email)
        ->and($policy->next(Channel::Email))->toBeNull();
});

it('rechaza pedir el siguiente de un canal ajeno a la política', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Email]);

    expect(fn () => $policy->next(Channel::Sms))
        ->toThrow(NotificationsException::class, 'no pertenece');
});

it('informa si contiene un canal', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Email]);

    expect($policy->contains(Channel::Email))->toBeTrue()
        ->and($policy->contains(Channel::Sms))->toBeFalse();
});

it('serializa y reconstruye desde su forma primitiva', function (): void {
    $policy = CascadePolicy::of([Channel::Fcm, Channel::Sms], EscalateCondition::OnFailure);

    expect($policy->toArray())->toBe([
        'channels' => ['FCM', 'SMS'],
        'escalate_when' => 'ON_FAILURE',
    ]);

    $restored = CascadePolicy::fromArray($policy->toArray());
    expect($restored->channels)->toBe([Channel::Fcm, Channel::Sms])
        ->and($restored->escalateWhen)->toBe(EscalateCondition::OnFailure);
});

it('usa la condición por defecto cuando el array no la trae', function (): void {
    $restored = CascadePolicy::fromArray(['channels' => ['FCM', 'EMAIL']]);

    expect($restored->escalateWhen)->toBe(EscalateCondition::NoOpen);
});

it('reconstruye desde datos persistidos traduciendo alias y colapsando duplicados', function (): void {
    $restored = CascadePolicy::fromStored(['channels' => ['PUSH_FCM', 'FCM', 'WEB_PUSH', 'SMS']]);

    expect($restored->channels)->toBe([Channel::Fcm, Channel::Sms])
        ->and($restored->escalateWhen)->toBe(EscalateCondition::NoOpen);
});

it('lanza al reconstruir si no queda ningún canal vigente', function (): void {
    expect(fn () => CascadePolicy::fromStored(['channels' => ['WEB_PUSH', 'WHATSAPP']]))
        ->toThrow(NotificationsException::class, 'al menos un canal');
});
