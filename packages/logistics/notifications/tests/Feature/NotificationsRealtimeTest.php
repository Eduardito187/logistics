<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logistics\Notifications\Application\Command\MarkNotificationRead\MarkNotificationRead;
use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSent;
use Logistics\Notifications\Application\Contract\NotificationFeedSignal;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Notifications\Infrastructure\Realtime\BroadcastNotificationsUpdated;
use Logistics\Notifications\Infrastructure\Realtime\NotificationFeedAudience;
use Logistics\Notifications\Presentation\Broadcasting\NotificationsUpdated;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Señal de tiempo real de la pantalla de notificaciones de la app: `notifications.updated` por el canal
// privado del usuario (`private-user.{id}`), siempre por la cola, tras el commit y con anti-ráfaga.
//
// El trabajo de difusión se intercepta con Bus::fake (qué se encola y para quién) y se ejecuta a mano con
// Event::fake para ver el evento que saldría al socket. Nada habla con un servidor de sockets real.

/** «Leer todas» de la app. */
const NRT_READ_ALL_URL = '/api/v1/app/notifications/read-all';

/**
 * Falla simulada dentro de una transacción externa, para forzar su reversión. Extiende RuntimeException
 * para que el `catch` del test la siga atrapando.
 */
final class NrtRollbackException extends RuntimeException {}

beforeEach(function (): void {
    $this->withoutMiddleware();

    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);

    Bus::fake([BroadcastNotificationsUpdated::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Activa un transporte de sockets (en los tests el default es `null`, que no emite nada).
 */
function nrtEnableSockets(): void
{
    config(['broadcasting.default' => 'pusher']);
}

/**
 * Notificación ya persistida, con fecha fija y desplazamiento explícito.
 */
function nrtNotification(string $createdAt = '2026-11-01T10:00:00+00:00'): NotificationRecord
{
    $at = Carbon::parse($createdAt);

    return NotificationRecord::factory()->create(['created_at' => $at, 'updated_at' => $at]);
}

/**
 * Siembra por el repositorio (sin pasar por los handlers, así no dispara la señal) una notificación
 * encolada con cascada de dos canales y devuelve su id.
 */
function nrtQueuedNotification(): string
{
    $id = NotificationId::generate();
    app(NotificationRepository::class)->save(Notification::queue(
        $id,
        Uuid::generate()->value,
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Fcm, Channel::Email]),
        'cliente@dismac.test',
    ));

    return $id->toString();
}

/**
 * Nombres de canal (con el prefijo `private-`) por los que saldría el evento.
 *
 * @return list<string>
 */
function nrtChannels(NotificationsUpdated $event): array
{
    return array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
}

// ──────────────────────────── Contrato del evento ────────────────────────────

it('el evento tiene nombre estable, va por la cola con un solo intento y nunca lleva carga vacía', function (): void {
    $event = new NotificationsUpdated([7, 9], NotificationsUpdated::SCOPE_USER);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('notifications.updated')
        ->and($event->tries)->toBe(1)
        ->and(nrtChannels($event))->toBe(['private-user.7', 'private-user.9'])
        ->and($event->broadcastWith())->toBe(['scope' => 'user'])
        ->and((new NotificationsUpdated([7]))->broadcastWith())->toBe(['scope' => 'feed'])
        ->and(NotificationsUpdated::channelName(7))->toBe('user.7');
});

it('un evento nunca supera el tope de canales por publicación', function (): void {
    $event = new NotificationsUpdated(range(1, NotificationsUpdated::MAX_CHANNELS + 50));

    expect($event->broadcastOn())->toHaveCount(NotificationsUpdated::MAX_CHANNELS);
});

// ──────────────────────────── Estado de lectura: solo al propio usuario ────────────────────────────

it('marcar una notificación como leída avisa solo a ese usuario, por la cola y con demora', function (): void {
    nrtEnableSockets();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $notification = nrtNotification();

    $this->actingAs($user)->postJson("/api/v1/app/notifications/{$notification->id}/read")->assertOk();

    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => $job->userIds === [(int) $user->id]
            && $job->audience === false
            && $job->delay === 3,
    );
    Bus::assertNotDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => in_array((int) $other->id, $job->userIds, true),
    );

    // Al ejecutarse el trabajo, sale el evento por el canal privado de ese usuario y de nadie más.
    Event::fake([NotificationsUpdated::class]);
    (new BroadcastNotificationsUpdated([(int) $user->id]))->handle(app(NotificationFeedAudience::class));

    Event::assertDispatchedTimes(NotificationsUpdated::class, 1);
    Event::assertDispatched(
        NotificationsUpdated::class,
        static fn (NotificationsUpdated $event): bool => nrtChannels($event) === ['private-user.'.$user->id]
            && $event->broadcastAs() === 'notifications.updated'
            && $event->broadcastWith() === ['scope' => 'user'],
    );
});

it('«leer todas» avisa solo al usuario que la pidió', function (): void {
    nrtEnableSockets();
    $user = User::factory()->create();
    nrtNotification();

    $this->actingAs($user)
        ->postJson(NRT_READ_ALL_URL, ['up_to' => '2026-11-02T00:00:00+00:00'])
        ->assertOk();

    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => $job->userIds === [(int) $user->id] && !$job->audience,
    );
});

it('anti-ráfaga: varias lecturas seguidas del mismo usuario encolan un solo aviso; otro usuario tiene el suyo', function (): void {
    nrtEnableSockets();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $first = nrtNotification('2026-11-01T10:00:00+00:00');
    $second = nrtNotification('2026-11-01T11:00:00+00:00');

    $this->actingAs($alice);
    $this->postJson("/api/v1/app/notifications/{$first->id}/read")->assertOk();
    $this->postJson("/api/v1/app/notifications/{$second->id}/read")->assertOk();
    $this->postJson(NRT_READ_ALL_URL)->assertOk();

    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);

    $this->actingAs($bob)->postJson("/api/v1/app/notifications/{$first->id}/read")->assertOk();

    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 2);
    Bus::assertDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => $job->userIds === [(int) $bob->id],
    );
});

// ──────────────────────────── Cambios del log: a la audiencia ────────────────────────────

it('encolar una notificación avisa a la audiencia del log', function (): void {
    nrtEnableSockets();

    $this->postJson('/api/v1/integrations/notifications', [
        'shipment_id' => Uuid::generate()->value,
        'message_type' => MessageType::EnRoute->value,
        'email' => 'cliente@dismac.test',
    ])->assertStatus(202);

    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => $job->audience === true && $job->userIds === [],
    );
});

it('suprimir, escalar y marcar enviada avisan a la audiencia, una sola vez por ventana', function (): void {
    nrtEnableSockets();
    $suppressed = nrtQueuedNotification();
    $escalated = nrtQueuedNotification();
    $sent = nrtQueuedNotification();

    $this->postJson("/api/v1/app/notifications/{$suppressed}/suppress")->assertStatus(202);
    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastNotificationsUpdated::class,
        static fn (BroadcastNotificationsUpdated $job): bool => $job->audience === true,
    );

    // Dentro de la misma ventana: no se encola otro aviso del log.
    $this->postJson("/api/v1/integrations/notifications/{$escalated}/advance-cascade")->assertStatus(202);
    app(CommandBus::class)->dispatch(new MarkNotificationSent($sent, Channel::Fcm->value));
    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 1);

    // Pasada la ventana, el siguiente cambio vuelve a avisar.
    Cache::forget('notifications:realtime:feed');
    app(CommandBus::class)->dispatch(new MarkNotificationSent($escalated, Channel::Email->value));
    Bus::assertDispatchedTimes(BroadcastNotificationsUpdated::class, 2);
});

it('el aviso del log llega a cada persona con rol, una sola vez, y no a quien no tiene rol', function (): void {
    nrtEnableSockets();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);
    $supervisor->assignRole(Role::Admin);
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $noRole = User::factory()->create();

    Event::fake([NotificationsUpdated::class]);
    (new BroadcastNotificationsUpdated([], true))->handle(app(NotificationFeedAudience::class));

    Event::assertDispatchedTimes(NotificationsUpdated::class, 1);
    Event::assertDispatched(NotificationsUpdated::class, static function (NotificationsUpdated $event) use ($admin, $supervisor, $driver, $noRole): bool {
        $channels = nrtChannels($event);

        return in_array('private-user.'.$admin->id, $channels, true)
            && in_array('private-user.'.$supervisor->id, $channels, true)
            && in_array('private-user.'.$driver->id, $channels, true)
            && !in_array('private-user.'.$noRole->id, $channels, true)
            && count($channels) === count(array_unique($channels))
            && $event->broadcastWith() === ['scope' => 'feed'];
    });
});

it('la audiencia es configurable y respeta el tope de destinatarios', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);

    $audience = app(NotificationFeedAudience::class);

    config(['logistics-notifications.realtime.audience_roles' => ['chofer']]);
    expect($audience->userIds())->toContain((int) $driver->id)->not->toContain((int) $admin->id);

    config(['logistics-notifications.realtime.audience_roles' => ['*']]);
    expect($audience->userIds())->toContain((int) $driver->id)->toContain((int) $admin->id);

    config(['logistics-notifications.realtime.max_recipients' => 1]);
    expect($audience->userIds())->toHaveCount(1);

    config(['logistics-notifications.realtime.audience_roles' => []]);
    expect($audience->userIds())->toBe([]);
});

// ──────────────────────────── Tolerancia ────────────────────────────

it('sin transporte de tiempo real no encola nada y la operación sigue funcionando', function (string $driver): void {
    config(['broadcasting.default' => $driver]);
    $user = User::factory()->create();
    $notification = nrtNotification();
    $queued = nrtQueuedNotification();

    $this->actingAs($user);
    $this->postJson("/api/v1/app/notifications/{$notification->id}/read")->assertOk();
    $this->postJson(NRT_READ_ALL_URL)->assertOk();
    $this->postJson("/api/v1/app/notifications/{$queued}/suppress")->assertStatus(202);

    Bus::assertNotDispatched(BroadcastNotificationsUpdated::class);

    // El trabajo, si quedó uno encolado de antes, tampoco emite.
    Event::fake([NotificationsUpdated::class]);
    (new BroadcastNotificationsUpdated([(int) $user->id]))->handle(app(NotificationFeedAudience::class));
    Event::assertNotDispatched(NotificationsUpdated::class);
})->with(['null', 'log']);

it('si la transacción del caso de uso se revierte, no se avisa nada', function (): void {
    nrtEnableSockets();
    $user = User::factory()->create();
    $notification = nrtNotification();

    try {
        DB::transaction(function () use ($user, $notification): void {
            app(CommandBus::class)->dispatch(new MarkNotificationRead($notification->id, (string) $user->id));

            throw new NrtRollbackException('falla posterior al caso de uso');
        });
    } catch (RuntimeException) {
        // esperado: la transacción externa se revierte.
    }

    Bus::assertNotDispatched(BroadcastNotificationsUpdated::class);
});

it('la señal nunca lanza aunque el caché falle, e ignora identidades sin canal', function (): void {
    nrtEnableSockets();
    $signal = app(NotificationFeedSignal::class);

    // Un id que no es entero no tiene canal personal: no encola nada.
    $signal->readStateChanged('no-es-un-id');
    $signal->readStateChanged('');
    Bus::assertNotDispatched(BroadcastNotificationsUpdated::class);

    Cache::shouldReceive('add')->andThrow(new RuntimeException('caché caído'));

    $signal->feedChanged();
    $signal->readStateChanged('15');

    Bus::assertNotDispatched(BroadcastNotificationsUpdated::class);
});
