<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

// Sesiones una-por-plataforma (estilo app de banco): máx 1 sesión activa por bucket (web|app) por usuario.
// web y app coexisten; un segundo dispositivo del MISMO bucket desaloja al anterior, que recibe un 401
// con code=session_superseded (distinguible del token expirado / baja normal).

// La guarda de la puerta app cachea el snapshot del dispositivo por id (TTL corto). Entre tests, con
// RefreshDatabase los ids se reciclan y el store de cache NO se resetea: se limpia para que un snapshot
// de un test previo no envenene a un dispositivo nuevo con el mismo id.
beforeEach(fn () => Cache::flush());

function makeUser(string $email): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make('secret-pass')]);
}

/**
 * Login por la puerta app. Devuelve la respuesta (token en `token` si no hay 2FA).
 */
function appLogin(string $email, string $fingerprint, string $platform = 'android', ?string $clientKind = null): TestResponse
{
    $device = ['fingerprint' => $fingerprint, 'platform' => $platform];
    if ($clientKind !== null) {
        $device['client_kind'] = $clientKind;
    }

    return test()->postJson('/api/v1/app/auth/login', [
        'email' => $email,
        'password' => 'secret-pass',
        'device' => $device,
    ]);
}

/**
 * GET /app/auth/me con un token dado. Resetea el guard antes: en un mismo test, varias requests
 * autenticadas con tokens DISTINTOS del mismo usuario comparten el guard de Sanctum, que cachea el
 * currentAccessToken de la primera. En producción cada request es un proceso fresco (no aplica); acá se
 * limpia para que cada token se resuelva como la request independiente que representa.
 */
function meWith(string $token): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/app/auth/me');
}

it('un segundo dispositivo del bucket app desaloja al anterior; el web sigue vivo', function (): void {
    $user = makeUser('multi@dismac.test');

    $tokenA = appLogin('multi@dismac.test', 'fp-app-A', 'android')->json('token');
    $tokenWeb = appLogin('multi@dismac.test', 'fp-web', 'web')->json('token');
    $tokenB = appLogin('multi@dismac.test', 'fp-app-B', 'ios')->json('token');

    // El dispositivo app A quedó desalojado con motivo session_superseded; B y el web, activos.
    $deviceA = Device::query()->where('fingerprint', 'fp-app-A')->firstOrFail();
    $deviceB = Device::query()->where('fingerprint', 'fp-app-B')->firstOrFail();
    $deviceWeb = Device::query()->where('fingerprint', 'fp-web')->firstOrFail();

    expect($deviceA->isRevoked())->toBeTrue()
        ->and($deviceA->revoked_reason)->toBe(Device::REVOKED_REASON_SUPERSEDED)
        ->and($deviceA->session_bucket)->toBe(Device::BUCKET_APP)
        ->and($deviceB->isRevoked())->toBeFalse()
        ->and($deviceB->session_bucket)->toBe(Device::BUCKET_APP)
        ->and($deviceWeb->isRevoked())->toBeFalse()
        ->and($deviceWeb->session_bucket)->toBe(Device::BUCKET_WEB);

    // A recibe 401 session_superseded; B y el web siguen operando.
    meWith($tokenA)
        ->assertStatus(401)->assertJsonPath('code', 'session_superseded');
    meWith($tokenB)->assertOk();
    meWith($tokenWeb)->assertOk();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('un segundo web desaloja al web anterior sin tocar la app', function (): void {
    $user = makeUser('web2@dismac.test');

    $tokenApp = appLogin('web2@dismac.test', 'fp-app', 'android')->json('token');
    $tokenW1 = appLogin('web2@dismac.test', 'fp-web-1', 'web')->json('token');
    $tokenW2 = appLogin('web2@dismac.test', 'fp-web-2', 'web')->json('token');

    meWith($tokenW1)
        ->assertStatus(401)->assertJsonPath('code', 'session_superseded');
    meWith($tokenW2)->assertOk();
    meWith($tokenApp)->assertOk();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('el mismo fingerprint es reemplazo natural, no desalojo (no se auto-supersede)', function (): void {
    makeUser('same@dismac.test');

    appLogin('same@dismac.test', 'fp-same', 'android')->assertOk();
    $token2 = appLogin('same@dismac.test', 'fp-same', 'android')->json('token');

    $device = Device::query()->where('fingerprint', 'fp-same')->firstOrFail();
    expect($device->isRevoked())->toBeFalse();
    meWith($token2)->assertOk();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('client_kind fuerza el bucket por encima del platform', function (): void {
    makeUser('kind@dismac.test');

    // platform android pero client_kind=web → va al bucket web.
    appLogin('kind@dismac.test', 'fp-forced-web', 'android', 'web')->assertOk();
    $device = Device::query()->where('fingerprint', 'fp-forced-web')->firstOrFail();

    expect($device->session_bucket)->toBe(Device::BUCKET_WEB);

    // Un login app real NO debe desalojar a esta sesión forzada a web.
    appLogin('kind@dismac.test', 'fp-real-app', 'android')->assertOk();
    expect($device->refresh()->isRevoked())->toBeFalse();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('distingue el 401 de desalojo del de baja normal del dispositivo', function (): void {
    $user = makeUser('codes@dismac.test');

    // Dispositivo revocado por BAJA (sin motivo de desalojo) pero con token vivo → device_revoked.
    $revoked = Device::create(['user_id' => $user->getKey(), 'fingerprint' => 'fp-revoked', 'platform' => 'android', 'session_bucket' => 'app', 'revoked_at' => now()]);
    $revokedToken = $user->createToken('t-revoked');
    /** @var PersonalAccessToken $rt */
    $rt = $revokedToken->accessToken;
    $rt->forceFill(['device_id' => $revoked->getKey()])->save();

    meWith($revokedToken->plainTextToken)
        ->assertStatus(401)->assertJsonPath('code', 'device_revoked');

    // Dispositivo desalojado (motivo session_superseded) con token vivo → session_superseded.
    $superseded = Device::create(['user_id' => $user->getKey(), 'fingerprint' => 'fp-sup', 'platform' => 'android', 'session_bucket' => 'app', 'revoked_at' => now(), 'revoked_reason' => Device::REVOKED_REASON_SUPERSEDED]);
    $supToken = $user->createToken('t-sup');
    /** @var PersonalAccessToken $st */
    $st = $supToken->accessToken;
    $st->forceFill(['device_id' => $superseded->getKey()])->save();

    meWith($supToken->plainTextToken)
        ->assertStatus(401)->assertJsonPath('code', 'session_superseded');
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

// Regresión (2026-11-11): login de OTRO usuario en el mismo teléfono (misma fila Device por fingerprint).
// El snapshot cacheado de la guarda (TTL 30 s) seguía con user_id del anterior → 401 device_revoked al
// token recién emitido. Device::saved invalida el snapshot al cambiar user_id/revoked_at/revoked_reason.
it('otro usuario en el mismo teléfono entra al instante (snapshot cacheado invalidado)', function (): void {
    makeUser('phone-a@dismac.test');
    makeUser('phone-b@dismac.test');

    $tokenA = appLogin('phone-a@dismac.test', 'fp-shared', 'android')->json('token');
    meWith($tokenA)->assertOk(); // calienta la cache con user_id = A

    $tokenB = appLogin('phone-b@dismac.test', 'fp-shared', 'android')->json('token');
    meWith($tokenB)->assertOk();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');

it('un teléfono desalojado que vuelve a loguear antes del TTL entra al instante', function (): void {
    makeUser('back@dismac.test');

    $tokenF = appLogin('back@dismac.test', 'fp-F', 'android')->json('token');
    meWith($tokenF)->assertOk();

    appLogin('back@dismac.test', 'fp-F2', 'android')->assertOk(); // desaloja F
    meWith($tokenF)->assertStatus(401)->assertJsonPath('code', 'session_superseded'); // cachea revoked=true

    $tokenF2nd = appLogin('back@dismac.test', 'fp-F', 'android')->json('token');
    meWith($tokenF2nd)->assertOk();
})->skip(fn (): bool => env('DB_CONNECTION') !== 'pgsql', 'requiere PostgreSQL');
