<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Event\MessageTemplateDefined;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\MessageTemplate;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageTemplateId;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Plantilla de ejemplo para el tipo En ruta por push.
 */
function sampleTemplate(string $body = 'Tu pedido {{shipment_id}} va en camino'): MessageTemplate
{
    return MessageTemplate::define(
        MessageTemplateId::generate(),
        MessageType::EnRoute,
        Channel::Fcm,
        'ES',
        'On the way',
        $body,
    );
}

it('defines a template, normalizing the locale and recording the event', function (): void {
    $template = sampleTemplate();

    expect($template->locale())->toBe('es');

    $events = $template->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MessageTemplateDefined::class)
        ->and($events[0]->eventName())->toBe('notifications.message_template_defined');
});

it('renders the body replacing the provided variables', function (): void {
    $rendered = sampleTemplate()->render(['shipment_id' => 'SHP-1']);

    expect($rendered->body)->toBe('Tu pedido SHP-1 va en camino')
        ->and($rendered->subject)->toBe('On the way');
});

it('leaves unknown variables empty instead of printing the marker', function (): void {
    $rendered = sampleTemplate('Hola {{unknown}}!')->render([]);

    expect($rendered->body)->toBe('Hola !');
});

it('rejects an empty body', function (): void {
    expect(fn () => sampleTemplate('   '))
        ->toThrow(NotificationsException::class, 'necesita un cuerpo');
});

it('revises the content and records a new event', function (): void {
    $template = sampleTemplate();
    $template->releaseEvents();

    $template->revise(null, 'Nuevo cuerpo {{shipment_id}}');

    expect($template->subject())->toBe('')
        ->and($template->render(['shipment_id' => 'X'])->body)->toBe('Nuevo cuerpo X')
        ->and($template->releaseEvents())->toHaveCount(1);
});
