<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;

// Auditoría app #28: la guarda de la puerta app registra la última IP del cliente (`last_ip`) en el mismo
// UPDATE ventaneado de `last_seen_at`, y GET /api/v1/app/devices la muestra SOLO para los equipos propios.

/**
 * @return array{0: string, 1: User, 2: Device}
 */
function deviceLastIpBearer(): array
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-ip-'.uniqid(),
        'platform' => 'android',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $user, $device];
}

it('registra la IP del cliente y la muestra en la lista de equipos propios', function (): void {
    [$token, , $device] = deviceLastIpBearer();

    $stranger = User::factory()->create();
    $foreign = Device::create([
        'user_id' => $stranger->getKey(),
        'fingerprint' => 'fp-ip-foreign',
        'platform' => 'ios',
        'last_ip' => '198.51.100.9',
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withToken($token)
        ->getJson('/api/v1/app/devices');

    $response->assertOk()
        ->assertJsonPath('data.0.uuid', $device->uuid)
        ->assertJsonPath('data.0.last_ip', '203.0.113.7')
        ->assertDontSee('198.51.100.9', false)
        ->assertDontSee($foreign->uuid, false);

    expect($device->fresh()?->last_ip)->toBe('203.0.113.7');
});

it('no reescribe la IP dentro de la ventana de last_seen (sin escrituras extra en el camino caliente)', function (): void {
    [$token, , $device] = deviceLastIpBearer();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withToken($token)
        ->getJson('/api/v1/app/devices')
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->withToken($token)
        ->getJson('/api/v1/app/devices')
        ->assertOk()
        ->assertJsonPath('data.0.last_ip', '203.0.113.7');

    expect($device->fresh()?->last_ip)->toBe('203.0.113.7');
});

it('acepta IPv6 y deja last_ip en null para un equipo que nunca se usó', function (): void {
    [$token, $user, $device] = deviceLastIpBearer();
    $idle = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-ip-idle',
        'platform' => 'web',
    ]);

    $rows = collect((array) $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])
        ->withToken($token)
        ->getJson('/api/v1/app/devices')
        ->assertOk()
        ->json('data'))->keyBy('uuid');

    expect($rows[$device->uuid]['last_ip'])->toBe('2001:db8::1')
        ->and($rows[$idle->uuid]['last_ip'])->toBeNull();
});
