<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\ValueObject\RenderedMessage;

it('recorta asunto y cuerpo', function (): void {
    $message = RenderedMessage::of('  Tu pedido salió  ', '  Ya está en ruta  ');

    expect($message->subject)->toBe('Tu pedido salió')
        ->and($message->body)->toBe('Ya está en ruta');
});

it('normaliza un asunto vacío a null (canales sin asunto)', function (): void {
    expect(RenderedMessage::of('   ', 'cuerpo')->subject)->toBeNull()
        ->and(RenderedMessage::of(null, 'cuerpo')->subject)->toBeNull();
});

it('expone su forma primitiva', function (): void {
    expect(RenderedMessage::of('Asunto', 'Cuerpo')->toArray())->toBe([
        'subject' => 'Asunto',
        'body' => 'Cuerpo',
    ])->and(RenderedMessage::of(null, 'Cuerpo')->toArray())->toBe([
        'subject' => null,
        'body' => 'Cuerpo',
    ]);
});
