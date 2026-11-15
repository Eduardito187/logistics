<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Jobs\SendStaffBroadcastPush;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// Avisos internos al personal: el admin escribe a un rol, a una tienda o a personas puntuales; cada uno lo ve
// en su bandeja y el push sale por la cola. Solo admin envía.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Queue::fake();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function sbaUser(Role $role, ?string $nodeId = null): User
{
    $user = User::factory()->create(['node_id' => $nodeId]);
    $user->assignRole($role);

    return $user;
}

it('envía un aviso a un rol: llega a la bandeja de cada uno y encola el push', function (): void {
    $admin = sbaUser(Role::Admin);
    $driverA = sbaUser(Role::Chofer);
    $driverB = sbaUser(Role::Chofer);
    $clerk = sbaUser(Role::Tienda);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/app/broadcasts', [
        'audience' => ['role' => 'chofer'],
        'title' => 'Reunión de choferes',
        'body' => 'Mañana 7:00 en el patio.',
        'link' => '/driver/home',
    ])->assertStatus(201);

    $id = (string) $response->json('data.id');
    $recipients = DB::table('staff_broadcast_recipients')->where('broadcast_id', $id)->pluck('user_id')->all();

    expect($recipients)->toContain($driverA->id, $driverB->id)
        ->and($recipients)->not->toContain($clerk->id);

    Queue::assertPushed(SendStaffBroadcastPush::class, static fn (SendStaffBroadcastPush $job): bool => $job->broadcastId === $id);

    Sanctum::actingAs($driverA);
    $this->getJson('/api/v1/app/inbox')
        ->assertOk()
        ->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('data.0.title', 'Reunión de choferes')
        ->assertJsonPath('data.0.link', '/driver/home')
        ->assertJsonPath('data.0.read', false);

    $this->postJson('/api/v1/app/inbox/'.$id.'/read')->assertOk();
    $this->getJson('/api/v1/app/inbox?unread=true')->assertOk()->assertJsonPath('meta.unread', 0)->assertJsonCount(0, 'data');

    // Quien no lo recibió no puede marcarlo.
    Sanctum::actingAs($clerk);
    $this->postJson('/api/v1/app/inbox/'.$id.'/read')->assertStatus(404);
});

it('envía a una tienda y a personas puntuales, y el admin ve los enviados con sus lecturas', function (): void {
    $admin = sbaUser(Role::Admin);
    $store = (string) Str::uuid7();
    $clerk = sbaUser(Role::Tienda, $store);
    $other = sbaUser(Role::Tienda, (string) Str::uuid7());
    $supervisor = sbaUser(Role::Supervisor);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/app/broadcasts', [
        'audience' => ['node_id' => $store, 'user_ids' => [$supervisor->id]],
        'title' => 'Inventario',
        'body' => 'Hoy se cierra a las 18:00.',
    ])->assertStatus(201)->assertJsonPath('data.recipient_count', 2);

    expect(DB::table('staff_broadcast_recipients')->where('user_id', $other->id)->exists())->toBeFalse();

    Sanctum::actingAs($clerk);
    $this->postJson('/api/v1/app/inbox/read-all')->assertOk()->assertJsonPath('data.marked', 1);

    Sanctum::actingAs($supervisor);
    $this->getJson('/api/v1/app/broadcasts')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Inventario')
        ->assertJsonPath('data.0.recipient_count', 2)
        ->assertJsonPath('data.0.read_count', 1)
        ->assertJsonPath('data.0.sent_by.id', $admin->id);
});

it('solo el admin envía, y un alcance vacío o sin nadie se rechaza', function (): void {
    Sanctum::actingAs(sbaUser(Role::Supervisor));
    $this->postJson('/api/v1/app/broadcasts', ['audience' => ['role' => 'chofer'], 'title' => 't', 'body' => 'b'])->assertStatus(403);

    Sanctum::actingAs(sbaUser(Role::Admin));
    $this->postJson('/api/v1/app/broadcasts', ['audience' => ['x' => 1], 'title' => 't', 'body' => 'b'])->assertStatus(422);
    $this->postJson('/api/v1/app/broadcasts', ['audience' => ['node_id' => (string) Str::uuid7()], 'title' => 't', 'body' => 'b'])->assertStatus(422);
});

it('sin FCM configurado el push no hace nada ni falla, y el aviso queda en la bandeja', function (): void {
    Http::fake();
    config(['logistics-notifications.fcm.project_id' => null]);

    $driver = sbaUser(Role::Chofer);
    $id = (string) Str::uuid7();
    DB::table('staff_broadcasts')->insert(['id' => $id, 'title' => 'T', 'body' => 'B', 'audience' => '{}', 'recipient_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('staff_broadcast_recipients')->insert(['id' => (string) Str::uuid7(), 'broadcast_id' => $id, 'user_id' => $driver->id, 'created_at' => now(), 'updated_at' => now()]);

    (new SendStaffBroadcastPush($id))->handle();

    Http::assertNothingSent();
    expect(DB::table('staff_broadcast_recipients')->where('broadcast_id', $id)->value('pushed_at'))->toBeNull();
});
