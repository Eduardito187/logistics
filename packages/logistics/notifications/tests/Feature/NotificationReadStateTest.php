<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Estado de lectura POR USUARIO de la bandeja de notificaciones de la app (pedido #9) y paginación del
 * listado (pedido #20): read_at por ítem, meta.unread_count, ?unread=true, marcar leída (idempotente),
 * leer todas (con up_to) y aislamiento entre usuarios.
 *
 * Se omite el middleware de puerta (auth/device); el usuario se fija con actingAs. Corre contra
 * logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea una notificación creada hace N horas (fecha fija para ordenar y para la marca de agua; relativa
 * a "ahora" porque «leer todas» recorta up_to al reloj real).
 */
function inboxNotification(int $hoursAgo): NotificationRecord
{
    $createdAt = Carbon::now()->subHours($hoursAgo)->startOfSecond();

    return NotificationRecord::factory()->create([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('marca una notificación como leída de forma idempotente (conserva el primer read_at)', function (): void {
    $user = User::factory()->create();
    $notification = inboxNotification(5);
    $this->actingAs($user);

    $first = $this->postJson("/api/v1/app/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.notification_id', $notification->id)
        ->assertJsonPath('data.unread_count', 0)
        ->json('data.read_at');

    expect($first)->toBeString();

    $this->travel(10)->minutes();

    $this->postJson("/api/v1/app/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.read_at', $first)
        ->assertJsonPath('data.unread_count', 0);

    $this->getJson("/api/v1/app/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.read_at', $first);
});

it('expone read_at por ítem, meta.unread_count y filtra con unread=true', function (): void {
    $user = User::factory()->create();
    $read = inboxNotification(5);
    $unread = inboxNotification(4);
    $this->actingAs($user);

    $this->postJson("/api/v1/app/notifications/{$read->id}/read")->assertOk();

    $all = $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1);

    $byId = collect($all->json('data'))->keyBy('id');
    expect($byId[$read->id]['read_at'])->toBeString()
        ->and($byId[$unread->id]['read_at'])->toBeNull()
        ->and($byId[$unread->id]['created_at'])->toBeString();

    $this->getJson('/api/v1/app/notifications/all?unread=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $unread->id)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.unread_count', 1);
});

it('marca todas como leídas, respetando up_to', function (): void {
    $user = User::factory()->create();
    inboxNotification(5);
    inboxNotification(4);
    $newer = inboxNotification(2);
    $this->actingAs($user);

    $this->postJson('/api/v1/app/notifications/read-all', ['up_to' => now()->subHours(3)->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    $this->getJson('/api/v1/app/notifications/all?unread=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newer->id);

    // Un up_to anterior no retrocede el estado (monótono).
    $this->postJson('/api/v1/app/notifications/read-all', ['up_to' => now()->subHours(6)->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    // Sin up_to: todo lo existente queda leído.
    $this->postJson('/api/v1/app/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 0);
});

it('un usuario no puede marcar como leída la notificación de otro (estado aislado por usuario)', function (): void {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $notification = inboxNotification(5);

    // Alice marca e intenta hacerlo a nombre de Bob: el user_id del cliente se ignora.
    $this->actingAs($alice);
    $this->postJson("/api/v1/app/notifications/{$notification->id}/read", ['user_id' => $bob->id])
        ->assertOk();
    $this->postJson('/api/v1/app/notifications/read-all', ['user_id' => $bob->id])->assertOk();

    // Para Bob sigue sin leer.
    $this->actingAs($bob);
    $this->getJson('/api/v1/app/notifications/all')
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.read_at', null);
    $this->getJson("/api/v1/app/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.read_at', null);
});

it('responde 404 si la notificación no existe y 422 si el id no es un UUID', function (): void {
    $this->actingAs(User::factory()->create());

    $this->postJson('/api/v1/app/notifications/'.Uuid::generate()->value.'/read')->assertNotFound();
    $this->postJson('/api/v1/app/notifications/no-es-uuid/read')->assertUnprocessable();
    $this->postJson('/api/v1/app/notifications/read-all', ['up_to' => 'no-es-fecha'])->assertUnprocessable();
});

it('responde 401 al marcar sin usuario autenticado', function (): void {
    $notification = inboxNotification(5);

    $this->postJson("/api/v1/app/notifications/{$notification->id}/read")->assertUnauthorized();
    $this->postJson('/api/v1/app/notifications/read-all')->assertUnauthorized();
});

it('pagina el listado con meta estándar, de la más reciente a la más antigua', function (): void {
    $this->actingAs(User::factory()->create());
    $oldest = inboxNotification(5);
    $middle = inboxNotification(4);
    $newest = inboxNotification(3);

    $this->getJson('/api/v1/app/notifications/all?page=1&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.1.id', $middle->id)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2)
        ->assertJsonPath('meta.next_page', 2)
        ->assertJsonPath('meta.unread_count', 3);

    $this->getJson('/api/v1/app/notifications/all?page=2&per_page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $oldest->id)
        ->assertJsonPath('meta.next_page', null);

    // Modo cursor: next_cursor encadena las páginas.
    $cursor = $this->getJson('/api/v1/app/notifications/all?cursor=&per_page=2')
        ->assertOk()
        ->assertJsonPath('meta.has_more', true)
        ->json('meta.next_cursor');

    $this->getJson('/api/v1/app/notifications/all?per_page=2&cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonPath('data.0.id', $oldest->id)
        ->assertJsonPath('meta.has_more', false);
});
