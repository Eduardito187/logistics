<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Instantes fijos reutilizados por las aserciones de la ventana de un minuto.
const HOT_PATH_AT = '2026-10-20 10:00:00';
const HOT_PATH_AFTER_WINDOW = '2026-10-20 10:01:30';

// Camino caliente de la puerta app (`/api/v1/app/*`): la guarda de dispositivo y Sanctum no deben
// escribir en cada request (last_seen_at / last_used_at con resolución de un minuto) y /auth/me no debe
// repetir consultas que la guarda ya hizo (dispositivo, roles).

/**
 * @return array{0: string, 1: Device, 2: PersonalAccessToken}
 */
function hotPathBearer(): array
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-'.uniqid(),
        'platform' => 'android',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $device, $accessToken];
}

/**
 * @return list<string>
 */
function hotPathQueries(Closure $fn): array
{
    $sql = [];
    DB::listen(static function ($q) use (&$sql): void {
        $sql[] = $q->sql;
    });
    $fn();

    return $sql;
}

/**
 * GET /auth/me como request "nuevo": se olvidan los guards para que Sanctum vuelva a resolver el token
 * desde la base (en producción cada request arranca de cero; en tests el guard queda memoizado).
 */
function hotPathMe(mixed $case, string $token): void
{
    assert($case instanceof TestCase);
    app('auth')->forgetGuards();

    $case->withToken($token)->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonStructure(['user' => ['id', 'roles'], 'device' => ['uuid'], 'abilities']);
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('no reescribe last_seen_at ni last_used_at en requests dentro del mismo minuto', function (): void {
    [$token, $device, $accessToken] = hotPathBearer();
    Carbon::setTestNow(HOT_PATH_AT);

    hotPathMe($this, $token);
    expect($device->fresh()?->last_seen_at?->toDateTimeString())->toBe(HOT_PATH_AT)
        ->and($accessToken->fresh()?->last_used_at?->toDateTimeString())->toBe(HOT_PATH_AT);

    Carbon::setTestNow('2026-10-20 10:00:40');
    $sql = hotPathQueries(fn () => hotPathMe($this, $token));

    $updates = array_values(array_filter($sql, static fn (string $s): bool => str_starts_with($s, 'update')));
    expect($updates)->toBe([])
        ->and($device->fresh()?->last_seen_at?->toDateTimeString())->toBe(HOT_PATH_AT);

    // Pasada la ventana, vuelve a registrar la actividad.
    Carbon::setTestNow(HOT_PATH_AFTER_WINDOW);
    hotPathMe($this, $token);
    expect($device->fresh()?->last_seen_at?->toDateTimeString())->toBe(HOT_PATH_AFTER_WINDOW)
        ->and($accessToken->fresh()?->last_used_at?->toDateTimeString())->toBe(HOT_PATH_AFTER_WINDOW);
});

it('/auth/me consulta el dispositivo y los roles una sola vez', function (): void {
    [$token] = hotPathBearer();
    hotPathMe($this, $token);

    $sql = hotPathQueries(fn () => hotPathMe($this, $token));

    $devices = array_filter($sql, static fn (string $s): bool => str_contains($s, 'from "devices"'));
    $roles = array_filter($sql, static fn (string $s): bool => str_contains($s, 'from "user_roles"'));
    expect($devices)->toHaveCount(1)->and($roles)->toHaveCount(1);
});

it('un cambio real del token (no solo last_used_at) se guarda aunque esté dentro de la ventana', function (): void {
    [, , $accessToken] = hotPathBearer();
    $accessToken->forceFill(['last_used_at' => now()])->save();

    $accessToken->forceFill(['name' => 'renombrado', 'last_used_at' => now()])->save();

    expect($accessToken->fresh()?->name)->toBe('renombrado');
});
