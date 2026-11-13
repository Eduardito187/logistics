<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages\CreateMessageTemplate;

/**
 * Cubre handleRecordCreation de la página de alta: despacha UpsertMessageTemplate al bus CQRS y recupera
 * el record por su clave única. Se invoca el método protegido por reflexión para ejercer el handler sin
 * el ciclo del formulario Filament (no driveable en test).
 */
it('crea una plantilla despachando el comando y devuelve el record persistido', function (): void {
    $page = new CreateMessageTemplate;

    $method = new ReflectionMethod($page, 'handleRecordCreation');
    $method->setAccessible(true);

    /** @var MessageTemplateRecord $record */
    $record = $method->invoke($page, [
        'message_type' => MessageType::Delivered->value,
        'channel' => Channel::Email->value,
        'locale' => 'ES',
        'subject' => 'Tu pedido fue entregado',
        'body' => 'Gracias por tu compra.',
    ]);

    expect($record)->toBeInstanceOf(MessageTemplateRecord::class)
        ->and($record->message_type)->toBe(MessageType::Delivered->value)
        ->and($record->channel)->toBe(Channel::Email->value)
        ->and($record->locale)->toBe('es')
        ->and($record->body)->toBe('Gracias por tu compra.');

    expect(MessageTemplateRecord::query()
        ->where('message_type', MessageType::Delivered->value)
        ->where('channel', Channel::Email->value)
        ->where('locale', 'es')
        ->exists())->toBeTrue();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);
