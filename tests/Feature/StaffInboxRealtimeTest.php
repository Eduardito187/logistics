<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Events\InboxUpdated;
use App\Http\Middleware\EnsureDeviceToken;
use App\Jobs\BroadcastInboxUpdated;
use App\Jobs\SendStaffBroadcastPush;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Staff\StaffInbox;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// Avisos en tiempo real de la bandeja interna: canal privado por usuario (`private-user.{id}`) con el evento
// `inbox.updated` {unread}, autorizado con el Bearer de la app, y siempre por la cola (nada síncrono).
const SIR_AUTH_URL = '/api/v1/app/broadcasting/auth';
const SIR_SOCKET_ID = '1234.5678';

beforeEach(function (): void {
    Queue::fake();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Usuario con un token Sanctum REAL ligado a un dispositivo vigente (lo que manda la app).
 *
 * @return array{0: string, 1: User}
 */
function sirBearer(Role $role = Role::Chofer): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-rt-'.uniqid(),
        'platform' => 'android',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $user];
}

/**
 * Activa el transporte de sockets (protocolo Pusher) con credenciales de prueba y vuelve a cargar los
 * canales sobre ese transporte (en los tests el default es `null`, que no autoriza nada).
 */
function sirEnableSockets(): void
{
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'test-key',
        'broadcasting.connections.pusher.secret' => 'test-secret',
        'broadcasting.connections.pusher.app_id' => 'test-app',
    ]);

    require base_path('routes/channels.php');
}

it('el canal user.{id} autoriza solo al dueño, con el Bearer de la app', function (): void {
    sirEnableSockets();
    [$token, $user] = sirBearer();
    $other = User::factory()->create();

    $own = 'private-user.'.$user->id;
    $this->withToken($token)->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => $own])
        ->assertOk()
        ->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', SIR_SOCKET_ID.':'.$own, 'test-secret')]);

    // El canal de otra persona se niega, con el motivo en `detail`.
    $this->withToken($token)->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => 'private-user.'.$other->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'forbidden')
        ->assertJsonPath('detail', 'You are not allowed to join this channel.');

    // Un chofer tampoco entra a los canales de la oficina.
    $this->withToken($token)->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => 'private-vehicle.'.Str::uuid7()])
        ->assertStatus(403);

    // Cuerpo incompleto o canal sin prefijo privado: 422.
    $this->withToken($token)->postJson(SIR_AUTH_URL, ['channel_name' => $own])->assertStatus(422);
    $this->withToken($token)->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => 'user.'.$user->id])->assertStatus(422);
});

it('sin Bearer no se firma ningún canal', function (): void {
    sirEnableSockets();
    $user = User::factory()->create();

    $this->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => 'private-user.'.$user->id])
        ->assertStatus(401);
});

it('sin servidor de sockets configurado responde 503 con el motivo y no rompe', function (): void {
    config(['broadcasting.default' => 'null']);
    [$token, $user] = sirBearer();

    $this->withToken($token)->postJson(SIR_AUTH_URL, ['socket_id' => SIR_SOCKET_ID, 'channel_name' => 'private-user.'.$user->id])
        ->assertStatus(503)
        ->assertJsonPath('code', 'service_unavailable')
        ->assertJsonPath('detail', 'Real-time updates are not enabled in this environment.');
});

it('inbox.updated viaja por el canal privado del usuario, encolado, con su no-leído', function (): void {
    $event = new InboxUpdated(42, 3);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('inbox.updated')
        ->and($event->broadcastWith())->toBe(['unread' => 3])
        ->and($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($event->broadcastOn()[0]->name)->toBe('private-user.42')
        ->and($event->broadcastQueue())->toBe((string) config('observability.metrics.queues.notifications', 'notifications'));
});

it('crear un aviso encola el push y el aviso en tiempo real, y este emite inbox.updated a cada destinatario', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $driverA = User::factory()->create();
    $driverA->assignRole(Role::Chofer);
    $driverB = User::factory()->create();
    $driverB->assignRole(Role::Chofer);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::Tienda);

    // B ya tenía un aviso sin leer: su no-leído debe llegar en 2.
    $previous = (string) Str::uuid7();
    DB::table('staff_broadcasts')->insert(['id' => $previous, 'title' => 'T', 'body' => 'B', 'audience' => '{}', 'recipient_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('staff_broadcast_recipients')->insert(['id' => (string) Str::uuid7(), 'broadcast_id' => $previous, 'user_id' => $driverB->id, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs($admin);
    $id = (string) $this->postJson('/api/v1/app/broadcasts', [
        'audience' => ['role' => 'chofer', 'user_ids' => []],
        'title' => 'Reunión',
        'body' => 'Mañana 7:00.',
    ])->assertStatus(201)->json('data.id');

    // El request solo encola: el push (con los datos que espera la app) y el aviso en tiempo real.
    Queue::assertPushed(SendStaffBroadcastPush::class, static fn (SendStaffBroadcastPush $job): bool => $job->broadcastId === $id
        && $job->data('/x') === ['type' => 'inbox', 'id' => $id, 'broadcast_id' => $id, 'link' => '/x']);

    $queued = [];
    Queue::assertPushed(BroadcastInboxUpdated::class, static function (BroadcastInboxUpdated $job) use (&$queued): bool {
        $queued = [...$queued, ...$job->userIds];

        return !$job->office;
    });
    expect($queued)->toContain($driverA->id, $driverB->id)
        ->and($queued)->not->toContain($clerk->id);

    // Al correr en la cola, calcula el no-leído vigente y emite a cada destinatario.
    config(['broadcasting.default' => 'log']);
    Event::fake([InboxUpdated::class]);
    (new BroadcastInboxUpdated(array_values(array_unique($queued))))->handle(app(StaffInbox::class));

    Event::assertDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $driverA->id && $e->unread === 1);
    Event::assertDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $driverB->id && $e->unread === 2);
    Event::assertNotDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $clerk->id);
});

it('marcar leído y leer todos avisan el nuevo no-leído solo si algo cambió', function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);

    $ids = [(string) Str::uuid7(), (string) Str::uuid7()];
    foreach ($ids as $id) {
        DB::table('staff_broadcasts')->insert(['id' => $id, 'title' => 'T', 'body' => 'B', 'audience' => '{}', 'recipient_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('staff_broadcast_recipients')->insert(['id' => (string) Str::uuid7(), 'broadcast_id' => $id, 'user_id' => $driver->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    Sanctum::actingAs($driver);
    $forDriver = static fn (BroadcastInboxUpdated $job): bool => $job->userIds === [$driver->id];

    $this->postJson('/api/v1/app/inbox/'.$ids[0].'/read')->assertOk();
    Queue::assertPushed(BroadcastInboxUpdated::class, 1);
    Queue::assertPushed(BroadcastInboxUpdated::class, $forDriver);

    // Repetir el mismo "leído" no cambia nada: no se avisa de nuevo.
    $this->postJson('/api/v1/app/inbox/'.$ids[0].'/read')->assertOk();
    Queue::assertPushed(BroadcastInboxUpdated::class, 1);

    $this->postJson('/api/v1/app/inbox/read-all')->assertOk()->assertJsonPath('data.marked', 1);
    Queue::assertPushed(BroadcastInboxUpdated::class, 2);

    $this->postJson('/api/v1/app/inbox/read-all')->assertOk()->assertJsonPath('data.marked', 0);
    Queue::assertPushed(BroadcastInboxUpdated::class, 2);

    expect(app(StaffInbox::class)->unread($driver->id))->toBe(0);
});

it('sin transporte de tiempo real el trabajo no emite nada (default seguro)', function (): void {
    config(['broadcasting.default' => 'null']);
    $driver = User::factory()->create();

    Event::fake([InboxUpdated::class]);
    (new BroadcastInboxUpdated([$driver->id]))->handle(app(StaffInbox::class));

    Event::assertNotDispatched(InboxUpdated::class);
});

it('el aviso a la oficina llega a admin y supervisor, no al resto', function (): void {
    config(['broadcasting.default' => 'log']);
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);

    // Una ráfaga de cambios encola un solo aviso (ventana anti-ráfaga).
    Cache::forget('staff-inbox:office-changed');
    app(StaffInbox::class)->officeChanged();
    app(StaffInbox::class)->officeChanged();
    Queue::assertPushed(BroadcastInboxUpdated::class, 1);
    Queue::assertPushed(BroadcastInboxUpdated::class, static fn (BroadcastInboxUpdated $job): bool => $job->office);

    Event::fake([InboxUpdated::class]);
    (new BroadcastInboxUpdated([], true))->handle(app(StaffInbox::class));

    Event::assertDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $admin->id);
    Event::assertDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $supervisor->id);
    Event::assertNotDispatched(InboxUpdated::class, static fn (InboxUpdated $e): bool => $e->userId === $driver->id);
});
