<?php

declare(strict_types=1);

use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\EditMessageTemplate;

/**
 * Cubre handleRecordUpdate de la página de edición: despacha UpsertMessageTemplate con la identidad del
 * record y el cuerpo editado. Se invoca por reflexión para ejercer el handler sin el ciclo del
 * formulario Filament (no driveable en test).
 */
it('actualiza el cuerpo de la plantilla despachando el comando', function (): void {
    $record = MessageTemplateRecord::factory()->create([
        'subject' => 'Asunto original',
        'body' => 'Cuerpo original',
    ]);

    $page = new EditMessageTemplate;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');
    $method->setAccessible(true);

    /** @var MessageTemplateRecord $updated */
    $updated = $method->invoke($page, $record, [
        'subject' => 'Asunto nuevo',
        'body' => 'Cuerpo nuevo',
    ]);

    expect($updated->body)->toBe('Cuerpo nuevo')
        ->and($updated->subject)->toBe('Asunto nuevo');

    expect(MessageTemplateRecord::query()->whereKey($record->getKey())->value('body'))
        ->toBe('Cuerpo nuevo');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('normaliza un asunto vacío a null al actualizar', function (): void {
    $record = MessageTemplateRecord::factory()->create([
        'subject' => 'Asunto original',
        'body' => 'Cuerpo original',
    ]);

    $page = new EditMessageTemplate;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');
    $method->setAccessible(true);

    /** @var MessageTemplateRecord $updated */
    $updated = $method->invoke($page, $record, [
        'subject' => '',
        'body' => 'Cuerpo nuevo',
    ]);

    expect($updated->subject)->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);
