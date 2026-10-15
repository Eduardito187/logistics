<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Channel\RoutingChannelSender;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentMessageTypeRuleRepository;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\EloquentSmsBudgetRepository;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Cobertura de las puertas HTTP del contexto (puerta app e integrations), los repositorios Eloquent de
// reglas de cascada y presupuesto de SMS, y el sender enrutador. Se omite el middevare de puerta para
// probar el contrato del controller + la cadena CQRS (patrón F). Corre contra PostgreSQL (logistics_test).

beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Siembra una notificación encolada (cascada de dos canales) y devuelve su id como string.
 */
function covSeedQueuedNotification(): string
{
    $id = NotificationId::generate();
    $notification = Notification::queue(
        $id,
        Uuid::generate()->value,
        MessageType::EnRoute,
        CascadePolicy::of([Channel::PushNtfy, Channel::Email]),
        'cliente@dismac.test',
    );
    app(NotificationRepository::class)->save($notification);

    return $id->toString();
}

// ──────────────────────────── Puerta app — plantillas y reglas (GET/PUT) ────────────────────────────

it('PUT /notification-templates crea/actualiza una plantilla y GET la lista', function (): void {
    $this->putJson('/api/v1/app/notification-templates', [
        'message_type' => MessageType::EnRoute->value,
        'channel' => Channel::Email->value,
        'locale' => 'es',
        'subject' => 'Tu pedido va en camino',
        'body' => 'Hola {{customer_name}}, tu pedido {{order_reference}} está en ruta.',
    ])->assertOk()
        ->assertJsonPath('data.status', 'saved');

    $this->getJson('/api/v1/app/notification-templates')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('PUT /notification-templates valida la entrada (422 sin cuerpo)', function (): void {
    $this->putJson('/api/v1/app/notification-templates', [
        'message_type' => 'NO_EXISTE',
        'channel' => 'NO_EXISTE',
    ])->assertStatus(422);
});

it('PUT /notification-rules configura la cascada y GET lista las reglas efectivas', function (): void {
    $this->putJson('/api/v1/app/notification-rules', [
        'message_type' => MessageType::Delivered->value,
        'channels' => [Channel::PushNtfy->value, Channel::Email->value],
        'escalate_when' => EscalateCondition::NoOpen->value,
    ])->assertOk()
        ->assertJsonPath('data.message_type', MessageType::Delivered->value);

    $this->getJson('/api/v1/app/notification-rules')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('PUT /notification-rules valida la entrada (422 sin canales)', function (): void {
    $this->putJson('/api/v1/app/notification-rules', [
        'message_type' => MessageType::Delivered->value,
        'channels' => [],
        'escalate_when' => EscalateCondition::NoOpen->value,
    ])->assertStatus(422);
});

it('GET /notification-sms-budget devuelve el presupuesto del período', function (): void {
    $this->getJson('/api/v1/app/notification-sms-budget')
        ->assertOk()
        ->assertJsonStructure(['data']);

    // Con período explícito válido.
    $this->getJson('/api/v1/app/notification-sms-budget?period=2026-10')
        ->assertOk();

    // Período mal formado: 422.
    $this->getJson('/api/v1/app/notification-sms-budget?period=malo')
        ->assertStatus(422);
});

// ──────────────────────────── Puerta integrations — encolar + cascada ────────────────────────────

it('POST /integrations/notifications encola una notificación (202)', function (): void {
    $this->postJson('/api/v1/integrations/notifications', [
        'shipment_id' => (string) Uuid::generate()->value,
        'message_type' => MessageType::EnRoute->value,
        'email' => 'cliente@dismac.test',
        'phone' => '+59170000000',
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted');
});

it('POST /integrations/notifications valida la entrada (422)', function (): void {
    $this->postJson('/api/v1/integrations/notifications', [
        'shipment_id' => 'no-uuid',
        'message_type' => 'NO_EXISTE',
    ])->assertStatus(422);
});

it('POST /integrations/notifications/{id}/advance-cascade escala una notificación encolada (202)', function (): void {
    $id = covSeedQueuedNotification();

    $this->postJson('/api/v1/integrations/notifications/'.$id.'/advance-cascade')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted');
});

it('POST /notifications/{id}/suppress valida el uuid (422 con id inválido)', function (): void {
    $this->postJson('/api/v1/app/notifications/not-a-uuid/suppress')
        ->assertStatus(422);
});

it('POST /notifications/{id}/suppress suprime una notificación encolada (202)', function (): void {
    $id = covSeedQueuedNotification();

    $this->postJson('/api/v1/app/notifications/'.$id.'/suppress')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'suppressed');
});

// ──────────────────────────── EloquentMessageTypeRuleRepository ────────────────────────────

it('el repositorio de reglas guarda, lee, lista y borra la política', function (): void {
    /** @var EloquentMessageTypeRuleRepository $repo */
    $repo = app(MessageTypeRuleRepository::class);

    $policy = CascadePolicy::of([Channel::PushNtfy, Channel::Email], EscalateCondition::OnFailure);
    $repo->save(MessageType::Arriving, $policy);

    $read = $repo->policyFor(MessageType::Arriving);
    expect($read)->toBeInstanceOf(CascadePolicy::class)
        ->and($read->first())->toBe(Channel::PushNtfy);

    expect($repo->all())->toHaveKey(MessageType::Arriving->value);

    $repo->remove(MessageType::Arriving);
    expect($repo->policyFor(MessageType::Arriving))->toBeNull();
});

// ──────────────────────────── EloquentSmsBudgetRepository ────────────────────────────

it('el repositorio de SMS consume presupuesto de forma atómica y respeta el tope', function (): void {
    /** @var EloquentSmsBudgetRepository $repo */
    $repo = app(SmsBudgetRepository::class);
    $period = '2026-10';

    // Tope 2: dos consumos pasan, el tercero se rechaza.
    expect($repo->consume($period, 2))->toBeTrue()
        ->and($repo->consume($period, 2))->toBeTrue()
        ->and($repo->consume($period, 2))->toBeFalse();

    $budget = $repo->current($period, 2);
    expect($budget->sent)->toBe(2);
});

// ──────────────────────────── RoutingChannelSender ────────────────────────────

it('el sender enrutador delega en el sender del canal pedido', function (): void {
    $fake = new class implements ChannelSender
    {
        /** @var list<string> */
        public array $captured = [];

        public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
        {
            $this->captured[] = $channel->value;
        }
    };

    $router = new RoutingChannelSender([Channel::Email->value => $fake]);

    $notification = Notification::queue(
        NotificationId::generate(),
        Uuid::generate()->value,
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Email]),
        'cliente@dismac.test',
    );

    $router->send($notification, Channel::Email, RenderedMessage::of('asunto', 'cuerpo'));

    expect($fake->captured)->toBe([Channel::Email->value]);
});

it('el sender enrutador lanza si no hay sender para el canal', function (): void {
    $router = new RoutingChannelSender([]);

    $notification = Notification::queue(
        NotificationId::generate(),
        Uuid::generate()->value,
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Sms]),
        null,
        '+59170000000',
    );

    expect(fn () => $router->send($notification, Channel::Sms, RenderedMessage::of(null, 'x')))
        ->toThrow(NotificationsException::class);
});
