<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Resources\AppOutboxFailureResource;
use App\Models\AppOutboxFailure;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Filament\Facades\Filament;

// POST /api/v1/app/outbox-failures: reporte en lote de acciones offline rechazadas, idempotente por id,
// con usuario/dispositivo tomados del token; y su listado en el admin para supervisores.

/**
 * @return array{0: string, 1: User, 2: Device}
 */
function outboxFailureBearer(): array
{
    $user = User::factory()->create(['name' => 'Chofer Reportero']);
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-failures-'.uniqid(),
        'platform' => 'android',
        'name' => 'Phone of the north route',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $user, $device];
}

/**
 * @return array<string, mixed>
 */
function outboxFailureBatch(): array
{
    return ['failures' => [
        [
            'id' => '01JA2B3C4D5E6F7G8H9J0KLMNP',
            'queue' => 'action',
            'kind' => 'recordLoadScan',
            'reason' => 'rejected',
            'status' => 409,
            'problem_code' => 'conflict',
            'attempts' => 1,
            'endpoint' => '/api/v1/app/shipments/0192f0c3-7a1b-7c2d-8e3f-4a5b6c7d8e9f/scans?x=1',
            'occurred_at' => '2026-10-16T14:03:11.000Z',
        ],
        [
            'id' => '01JA2B3C4D5E6F7G8H9J0KLMNQ',
            'queue' => 'photo',
            'kind' => 'pod',
            'reason' => 'retries_exhausted',
            'status' => null,
            'problem_code' => null,
            'attempts' => 50,
            'occurred_at' => '2026-10-16T14:05:00.000Z',
        ],
    ]];
}

it('guarda el lote con usuario y dispositivo del token y responde 202', function (): void {
    [$token, $user, $device] = outboxFailureBearer();

    $this->withToken($token)
        ->postJson('/api/v1/app/outbox-failures', outboxFailureBatch(), ['X-App-Version' => '1.4.2'])
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.received', 2)
        ->assertJsonPath('data.stored', 2)
        ->assertJsonPath('data.duplicates', 0);

    $scan = AppOutboxFailure::query()->where('client_id', '01JA2B3C4D5E6F7G8H9J0KLMNP')->firstOrFail();
    expect($scan->user_id)->toBe($user->getKey())
        ->and($scan->device_id)->toBe($device->getKey())
        ->and($scan->http_status)->toBe(409)
        ->and($scan->app_version)->toBe('1.4.2')
        // La ruta queda sin query y con el UUID enmascarado.
        ->and($scan->endpoint)->toBe('/api/v1/app/shipments/{id}/scans');
});

it('es idempotente por id: reenviar el mismo lote no duplica', function (): void {
    [$token] = outboxFailureBearer();

    $this->withToken($token)->postJson('/api/v1/app/outbox-failures', outboxFailureBatch())->assertStatus(202);

    $this->withToken($token)->postJson('/api/v1/app/outbox-failures', outboxFailureBatch())
        ->assertStatus(202)
        ->assertJsonPath('data.stored', 0)
        ->assertJsonPath('data.duplicates', 2);

    expect(AppOutboxFailure::query()->count())->toBe(2);
});

it('valida el lote (motivo desconocido → 422)', function (): void {
    [$token] = outboxFailureBearer();

    $batch = outboxFailureBatch();
    $batch['failures'][0]['reason'] = 'whatever';

    $this->withToken($token)->postJson('/api/v1/app/outbox-failures', $batch)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect(AppOutboxFailure::query()->count())->toBe(0);
});

it('exige autenticación', function (): void {
    $this->postJson('/api/v1/app/outbox-failures', outboxFailureBatch())->assertUnauthorized();
});

it('lista las fallas en el admin para supervisores, sin UUIDs', function (): void {
    // Se siembra la fila directamente (sin pasar por el token de la puerta app): así el GET del admin
    // corre con la sesión web del supervisor, sin que el Bearer del chofer se cuele por Sanctum y lo pise.
    $reporter = User::factory()->create(['name' => 'Chofer Reportero']);
    $device = Device::create([
        'user_id' => $reporter->getKey(),
        'fingerprint' => 'fp-failures-'.uniqid(),
        'platform' => 'android',
        'name' => 'Phone of the north route',
    ]);
    AppOutboxFailure::create([
        'client_id' => '01JA2B3C4D5E6F7G8H9J0KLMNP',
        'user_id' => $reporter->getKey(),
        'device_id' => $device->getKey(),
        'queue' => 'action',
        'kind' => 'recordLoadScan',
        'reason' => 'rejected',
        'http_status' => 409,
        'problem_code' => 'conflict',
        'endpoint' => '/api/v1/app/shipments/{id}/scans',
        'attempts' => 1,
        'occurred_at' => '2026-10-16T14:03:11+00:00',
    ]);

    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);

    $response = $this->actingAs($supervisor)->get('/admin/app-outbox-failures');

    $response->assertOk()
        ->assertSee('recordLoadScan')
        ->assertSee('Chofer Reportero')
        ->assertSee('Phone of the north route');

    expect((string) $response->getContent())->not->toContain($device->uuid);
});

it('marca una falla como resuelta (idempotente)', function (): void {
    [$token] = outboxFailureBearer();
    $this->withToken($token)->postJson('/api/v1/app/outbox-failures', outboxFailureBatch())->assertStatus(202);

    $failure = AppOutboxFailure::query()->firstOrFail();
    $failure->markResolved('Supervisor One');
    $resolvedAt = $failure->fresh()?->resolved_at;

    $failure->fresh()?->markResolved('Someone Else');

    expect($failure->fresh()?->resolved_by)->toBe('Supervisor One')
        ->and($failure->fresh()?->resolved_at?->equalTo($resolvedAt))->toBeTrue();
});

it('el listado del admin es solo de oficina (admin/supervisor)', function (): void {
    Filament::setCurrentPanel('admin');

    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $this->actingAs($driver);
    expect(AppOutboxFailureResource::canAccess())->toBeFalse();

    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);
    $this->actingAs($supervisor);
    expect(AppOutboxFailureResource::canAccess())->toBeTrue();
});
