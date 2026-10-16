<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascade;
use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSent;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotificationHandler;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotification;
use Logistics\Notifications\Application\Command\UpsertMessageTemplate\UpsertMessageTemplate;
use Logistics\Notifications\Application\Query\GetMessageTemplate\GetMessageTemplate;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\Query\GetSmsBudget\GetSmsBudget;
use Logistics\Notifications\Application\Query\ListMessageTemplates\ListMessageTemplates;
use Logistics\Notifications\Application\Query\ListMessageTypeRules\ListMessageTypeRules;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\ReadModel\MessageTemplateView;
use Logistics\Notifications\Application\ReadModel\MessageTypeRuleView;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Application\ReadModel\SmsBudgetView;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\SmsBudgetRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de la capa de Aplicación del contexto de notificaciones (patrón C): comandos y consultas
 * por los buses CQRS, que ejercitan repositorios Eloquent, mappers y read models. El despacho físico
 * (push/email/SMS) vive en el relay del outbox y no se dispara aquí.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Encola una notificación por el handler directo y devuelve su id (para operar la cascada después).
 */
function coverageQueueNotification(string $messageType = 'EN_ROUTE', ?string $email = null): string
{
    $handler = app(QueueNotificationHandler::class);
    $handler->handle(new QueueNotification(
        shipmentId: Uuid::generate()->value,
        messageType: $messageType,
        email: $email,
    ));

    return $handler->lastNotificationId()->toString();
}

it('encola una notificación y la devuelve en el log y por id', function (): void {
    $id = coverageQueueNotification('EN_ROUTE', 'cliente@dismac.test');

    $list = app(QueryBus::class)->ask(new ListNotifications(50));
    $one = app(QueryBus::class)->ask(new GetNotification($id));

    expect($list)->toBeArray()
        ->and($list[0])->toBeInstanceOf(NotificationView::class)
        ->and($one)->toBeInstanceOf(NotificationView::class)
        ->and($one->id)->toBe($id);
});

it('suprime una notificación encolada', function (): void {
    $id = coverageQueueNotification();

    app(CommandBus::class)->dispatch(new SuppressNotification($id));

    $view = app(QueryBus::class)->ask(new GetNotification($id));
    expect($view->status)->toBe('SUPPRESSED');
});

it('avanza la cascada de una notificación encolada', function (): void {
    $id = coverageQueueNotification();

    app(CommandBus::class)->dispatch(new AdvanceCascade($id));

    $view = app(QueryBus::class)->ask(new GetNotification($id));
    expect($view)->toBeInstanceOf(NotificationView::class);
});

it('marca una notificación como enviada por un canal', function (): void {
    $id = coverageQueueNotification();

    app(CommandBus::class)->dispatch(new MarkNotificationSent($id, Channel::Fcm->value));

    $view = app(QueryBus::class)->ask(new GetNotification($id));
    expect($view->status)->toBe('SENT');
});

it('crea y luego revisa una plantilla de mensaje (upsert)', function (): void {
    $cmd = fn (string $body): UpsertMessageTemplate => new UpsertMessageTemplate(
        messageType: MessageType::EnRoute->value,
        channel: Channel::Email->value,
        locale: 'es',
        subject: 'En camino',
        body: $body,
    );

    app(CommandBus::class)->dispatch($cmd('Tu pedido {{shipment_id}} va en camino.'));
    app(CommandBus::class)->dispatch($cmd('Tu pedido {{shipment_id}} salió en ruta.'));

    $all = app(QueryBus::class)->ask(new ListMessageTemplates);
    $one = app(QueryBus::class)->ask(new GetMessageTemplate(
        MessageType::EnRoute->value,
        Channel::Email->value,
        'es',
    ));

    expect($all)->toBeArray()->toHaveCount(1)
        ->and($all[0])->toBeInstanceOf(MessageTemplateView::class)
        ->and($one)->toBeInstanceOf(MessageTemplateView::class)
        ->and($one->body)->toBe('Tu pedido {{shipment_id}} salió en ruta.');
});

it('devuelve null cuando no hay plantilla para el tipo/canal/locale', function (): void {
    $one = app(QueryBus::class)->ask(new GetMessageTemplate(
        MessageType::Delivered->value,
        Channel::Sms->value,
        'en',
    ));

    expect($one)->toBeNull();
});

it('devuelve la política efectiva de cada tipo de mensaje', function (): void {
    $views = app(QueryBus::class)->ask(new ListMessageTypeRules);

    expect($views)->toBeArray()
        ->and($views)->not->toBeEmpty()
        ->and($views[0])->toBeInstanceOf(MessageTypeRuleView::class);
});

it('devuelve el presupuesto de SMS del período', function (): void {
    SmsBudgetRecord::factory()->create(['period' => '2026-01', 'sent' => 7]);

    $view = app(QueryBus::class)->ask(new GetSmsBudget('2026-01'));

    expect($view)->toBeInstanceOf(SmsBudgetView::class)
        ->and($view->sent)->toBe(7);
});

it('usa el período actual cuando no se pasa uno', function (): void {
    $view = app(QueryBus::class)->ask(new GetSmsBudget);

    expect($view)->toBeInstanceOf(SmsBudgetView::class);
});
