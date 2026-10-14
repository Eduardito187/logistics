<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Plantilla con el cuerpo dado, por push y en español.
 */
function templateWithBody(string $body, ?string $subject = null): MessageTemplate
{
    return MessageTemplate::define(
        MessageTemplateId::generate(),
        MessageType::EnRoute,
        Channel::PushNtfy,
        'es',
        $subject,
        $body,
    );
}

it('interpolates markers with surrounding whitespace and mixed case', function (): void {
    $rendered = templateWithBody('Pedido {{ Order_Ref }} para {{customer_name}}')
        ->render(['order_ref' => 'OC-7', 'customer_name' => 'Ana']);

    // El marcador admite espacios internos y es case-insensitive sobre el nombre de la variable.
    expect($rendered->body)->toBe('Pedido OC-7 para Ana');
});

it('replaces every occurrence of a repeated variable', function (): void {
    $rendered = templateWithBody('{{eta}} — llega {{eta}}')->render(['eta' => '15:30']);

    expect($rendered->body)->toBe('15:30 — llega 15:30');
});

it('interpolates the subject too (email), leaving empty subject as null', function (): void {
    $rendered = templateWithBody('cuerpo {{x}}', 'Asunto {{x}}')->render(['x' => 'Z']);

    expect($rendered->subject)->toBe('Asunto Z')
        ->and($rendered->body)->toBe('cuerpo Z');
});

it('drops a marker whose variable was not provided, without leaking the braces', function (): void {
    $rendered = templateWithBody('Hola {{missing}} fin')->render(['other' => 'x']);

    expect($rendered->body)->toBe('Hola  fin');
});
