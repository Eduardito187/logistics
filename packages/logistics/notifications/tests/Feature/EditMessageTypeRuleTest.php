<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTypeRuleResource\Pages\EditMessageTypeRule;

/**
 * Cubre handleRecordUpdate de la página de edición de reglas: despacha SetMessageTypeRule con la nueva
 * cascada manteniendo el tipo del record. Se invoca por reflexión para ejercer el handler sin el ciclo
 * del formulario Filament (no driveable en test).
 */
it('reemplaza la cascada de la regla despachando el comando', function (): void {
    $record = MessageTypeRuleRecord::factory()->create();

    $page = new EditMessageTypeRule;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');
    $method->setAccessible(true);

    /** @var MessageTypeRuleRecord $updated */
    $updated = $method->invoke($page, $record, [
        'channels' => [Channel::Fcm->value, Channel::Sms->value],
        'escalate_when' => EscalateCondition::OnFailure->value,
    ]);

    expect($updated->channels)->toBe([Channel::Fcm->value, Channel::Sms->value])
        ->and($updated->escalate_when)->toBe(EscalateCondition::OnFailure->value);

    expect(MessageTypeRuleRecord::query()->whereKey($record->getKey())->value('escalate_when'))
        ->toBe(EscalateCondition::OnFailure->value);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);
