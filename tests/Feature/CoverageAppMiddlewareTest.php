<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\AuthenticateChannel;
use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureRole;
use App\Models\ApiChannel;
use App\Models\Device;
use App\Models\ServiceClient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\TransientToken;

// Ejercita los middleware de las puertas de la API invocándolos directamente con un request armado a
// mano y un "next" que devuelve 200: así se recorren las ramas de credenciales ausentes/ inválidas,
// rol/scope insuficiente y el camino feliz, sin depender del wiring de rutas. Las respuestas de error
// son Problem Details (RFC 7807) con el estado del ErrorCode.

/**
 * next que marca que la cadena continuó (200).
 */
function passThrough(): callable
{
    return static fn (Request $request): Response => new Response('ok', 200);
}

// ── EnsureRole ─────────────────────────────────────────────────────────────
it('EnsureRole rechaza sin usuario autenticado (401)', function (): void {
    $response = (new EnsureRole)->handle(Request::create('/x', 'GET'), passThrough(), 'admin');

    expect($response->getStatusCode())->toBe(401);
});

it('EnsureRole rechaza a un rol que no es el requerido (403)', function (): void {
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $driver);

    $response = (new EnsureRole)->handle($request, passThrough(), 'admin');

    expect($response->getStatusCode())->toBe(403);
});

it('EnsureRole deja pasar al rol correcto', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $admin);

    $response = (new EnsureRole)->handle($request, passThrough(), 'supervisor', 'admin');

    expect($response->getStatusCode())->toBe(200);
});

// ── EnsureAbility ───────────────────────────────────────────────────────────
it('EnsureAbility rechaza sin usuario (401)', function (): void {
    $response = (new EnsureAbility)->handle(Request::create('/x', 'GET'), passThrough(), 'execution:write');

    expect($response->getStatusCode())->toBe(401);
});

it('EnsureAbility rechaza cuando el token carece de la habilidad (403)', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['execution:read'])->accessToken;
    $user->withAccessToken($token);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $response = (new EnsureAbility)->handle($request, passThrough(), 'execution:write');

    expect($response->getStatusCode())->toBe(403);
});

it('EnsureAbility deja pasar con la habilidad concedida', function (): void {
    $user = User::factory()->create();
    // El token transitorio concede cualquier habilidad (tokenCan => true), como el admin con comodín.
    $user->withAccessToken(new TransientToken);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $response = (new EnsureAbility)->handle($request, passThrough(), 'execution:write');

    expect($response->getStatusCode())->toBe(200);
});

// ── AuthenticateServiceClient ────────────────────────────────────────────────
it('AuthenticateServiceClient rechaza sin credenciales (401)', function (): void {
    $response = (new AuthenticateServiceClient)->handle(Request::create('/x', 'GET'), passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('AuthenticateServiceClient rechaza credenciales inválidas (401)', function (): void {
    $request = Request::create('/x', 'GET');
    $request->headers->set('X-Client-Id', 'svc_unknown');
    $request->headers->set('X-Client-Secret', 'nope');

    $response = (new AuthenticateServiceClient)->handle($request, passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('AuthenticateServiceClient rechaza un scope insuficiente (403)', function (): void {
    $secret = ServiceClient::generateClientSecret();
    $client = ServiceClient::create([
        'name' => 'SAP',
        'client_id' => ServiceClient::generateClientId(),
        'client_secret' => $secret,
        'scopes' => ['shipments'],
        'active' => true,
    ]);

    $request = Request::create('/x', 'GET');
    $request->headers->set('X-Client-Id', $client->client_id);
    $request->headers->set('X-Client-Secret', $secret);

    $response = (new AuthenticateServiceClient)->handle($request, passThrough(), 'carriers');

    expect($response->getStatusCode())->toBe(403);
});

it('AuthenticateServiceClient deja pasar con credenciales y scope válidos', function (): void {
    $secret = ServiceClient::generateClientSecret();
    $client = ServiceClient::create([
        'name' => 'SAP',
        'client_id' => ServiceClient::generateClientId(),
        'client_secret' => $secret,
        'scopes' => ['shipments'],
        'active' => true,
    ]);

    $request = Request::create('/x', 'GET');
    $request->headers->set('X-Client-Id', $client->client_id);
    $request->headers->set('X-Client-Secret', $secret);

    $response = (new AuthenticateServiceClient)->handle($request, passThrough(), 'shipments');

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get(AuthenticateServiceClient::ATTRIBUTE))->toBeInstanceOf(ServiceClient::class);
});

// ── AuthenticateChannel ──────────────────────────────────────────────────────
it('AuthenticateChannel rechaza sin credenciales (401)', function (): void {
    $response = (new AuthenticateChannel)->handle(Request::create('/x', 'GET'), passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('AuthenticateChannel deja pasar con key y secret válidos', function (): void {
    $secret = ApiChannel::generateSecret();
    $channel = ApiChannel::create([
        'name' => 'Customer app',
        'key' => ApiChannel::generateKey(),
        'secret' => $secret,
        'rate_limit_per_minute' => 60,
        'active' => true,
    ]);

    $request = Request::create('/x', 'GET');
    $request->headers->set('X-Channel-Key', $channel->key);
    $request->headers->set('X-Channel-Secret', $secret);

    $response = (new AuthenticateChannel)->handle($request, passThrough());

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get(AuthenticateChannel::ATTRIBUTE))->toBeInstanceOf(ApiChannel::class);
});

// ── EnsureDeviceToken ─────────────────────────────────────────────────────────
it('EnsureDeviceToken rechaza sin usuario (401)', function (): void {
    $response = (new EnsureDeviceToken)->handle(Request::create('/x', 'GET'), passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('EnsureDeviceToken rechaza un token no ligado a un dispositivo (401)', function (): void {
    $user = User::factory()->create();
    // Token transitorio: no es un PersonalAccessToken con device_id, así que falla el binding.
    $user->withAccessToken(new TransientToken);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $response = (new EnsureDeviceToken)->handle($request, passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('EnsureDeviceToken rechaza un dispositivo revocado (401)', function (): void {
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-revoked',
        'platform' => 'android',
        'revoked_at' => now(),
    ]);

    $token = $user->createToken('test')->accessToken;
    $token->forceFill(['device_id' => $device->getKey()])->save();
    $user->withAccessToken($token);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $response = (new EnsureDeviceToken)->handle($request, passThrough());

    expect($response->getStatusCode())->toBe(401);
});

it('EnsureDeviceToken deja pasar un token ligado a un dispositivo vigente', function (): void {
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-active',
        'platform' => 'android',
    ]);

    $token = $user->createToken('test')->accessToken;
    $token->forceFill(['device_id' => $device->getKey()])->save();
    $user->withAccessToken($token);

    $request = Request::create('/x', 'GET');
    $request->setUserResolver(fn (): User => $user);

    $response = (new EnsureDeviceToken)->handle($request, passThrough());

    expect($response->getStatusCode())->toBe(200);
});
