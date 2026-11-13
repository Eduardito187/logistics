<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\CreateMessageTypeRule;

/**
 * Cubre handleRecordCreation de la página de alta de reglas: despacha SetMessageTypeRule al bus CQRS y
 * recupera el record por su clave (el tipo de mensaje). Se invoca por reflexión para ejercer el handler
 * sin el ciclo del formulario Filament (no driveable en test).
 */
it('crea una regla de cascada despachando el comando y devuelve el record persistido', function (): void {
    $page = new CreateMessageTypeRule;

    $method = new ReflectionMethod($page, 'handleRecordCreation');
    $method->setAccessible(true);

    /** @var MessageTypeRuleRecord $record */
    $record = $method->invoke($page, [
        'message_type' => MessageType::EnRoute->value,
        'channels' => [Channel::Fcm->value, Channel::Email->value],
        'escalate_when' => EscalateCondition::NoOpen->value,
    ]);

    expect($record)->toBeInstanceOf(MessageTypeRuleRecord::class)
        ->and($record->message_type)->toBe(MessageType::EnRoute->value)
        ->and($record->channels)->toBe([Channel::Fcm->value, Channel::Email->value]);

    expect(MessageTypeRuleRecord::query()->whereKey(MessageType::EnRoute->value)->exists())->toBeTrue();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);
