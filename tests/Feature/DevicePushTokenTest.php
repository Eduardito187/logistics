<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;

// Verifica el endpoint PUT /api/v1/app/devices/current/push-token: actualiza el push token (FID) del
// dispositivo en uso (resuelto del token Sanctum), acepta null (baja del push) y exige autenticación.

/**
 * Crea un usuario con un dispositivo y un token Sanctum ligado a ese dispositivo; devuelve el token en
 * claro para usarlo como Bearer, junto al dispositivo.
 *
 * @return array{0: string, 1: Device}
 */
function deviceBearer(?string $pushToken = null): array
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-'.uniqid(),
        'platform' => 'android',
        'push_token' => $pushToken,
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $device];
}

it('actualiza el push token del dispositivo en uso (200/204)', function (): void {
    [$token, $device] = deviceBearer('old-fid');

    $response = $this->withToken($token)
        ->putJson('/api/v1/app/devices/current/push-token', ['push_token' => 'new-fid-123']);

    $response->assertNoContent();

    expect($device->fresh()->push_token)->toBe('new-fid-123');
});

it('acepta push_token nulo para dar de baja al dispositivo del push', function (): void {
    [$token, $device] = deviceBearer('old-fid');

    $response = $this->withToken($token)
        ->putJson('/api/v1/app/devices/current/push-token', ['push_token' => null]);

    $response->assertNoContent();

    expect($device->fresh()->push_token)->toBeNull();
});

it('rechaza al usuario no autenticado (401)', function (): void {
    $response = $this->putJson('/api/v1/app/devices/current/push-token', ['push_token' => 'x']);

    $response->assertUnauthorized();
});

it('no toca el token de otro dispositivo del mismo usuario', function (): void {
    [$token, $device] = deviceBearer('mine-old');

    $user = $device->user;
    $other = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-other',
        'platform' => 'ios',
        'push_token' => 'other-fid',
    ]);

    $this->withToken($token)
        ->putJson('/api/v1/app/devices/current/push-token', ['push_token' => 'mine-new'])
        ->assertNoContent();

    expect($device->fresh()->push_token)->toBe('mine-new')
        ->and($other->fresh()->push_token)->toBe('other-fid');
});
