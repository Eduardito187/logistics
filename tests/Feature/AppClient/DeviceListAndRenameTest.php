<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;

// GET /api/v1/app/devices distingue teléfonos (auditoría app #28): is_current, display_name, modelo,
// versión de la app; y PATCH /api/v1/app/devices/{uuid} renombra SOLO un equipo propio.

/**
 * @return array{0: string, 1: User, 2: Device}
 */
function deviceListBearer(): array
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-list-'.uniqid(),
        'platform' => 'android',
        'name' => 'App interna',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $user, $device];
}

it('marca el dispositivo del token como is_current y registra modelo y versión desde las cabeceras', function (): void {
    [$token, $user, $current] = deviceListBearer();
    $other = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-list-other',
        'platform' => 'ios',
        'name' => 'App interna',
    ]);

    $response = $this->withToken($token)->getJson('/api/v1/app/devices', [
        'X-App-Version' => '1.4.2',
        'X-App-Build' => '42',
        'X-Device-Model' => 'Samsung SM-A515F',
        'X-Device-Os-Version' => 'Android 13',
    ]);

    $response->assertOk();
    $rows = collect((array) $response->json('data'))->keyBy('uuid');

    expect($rows[$current->uuid]['is_current'])->toBeTrue()
        ->and($rows[$current->uuid]['model'])->toBe('Samsung SM-A515F')
        ->and($rows[$current->uuid]['app_version'])->toBe('1.4.2')
        ->and($rows[$current->uuid]['app_build'])->toBe('42')
        ->and($rows[$current->uuid]['os_version'])->toBe('Android 13')
        // El nombre genérico no sirve para distinguir: se muestra el modelo.
        ->and($rows[$current->uuid]['display_name'])->toBe('Samsung SM-A515F')
        ->and($rows[$other->uuid]['is_current'])->toBeFalse()
        ->and($rows[$other->uuid]['display_name'])->toBe('iOS device');
});

it('renombra el dispositivo actual con el alias current', function (): void {
    [$token, , $current] = deviceListBearer();

    $this->withToken($token)->patchJson('/api/v1/app/devices/current', ['name' => 'Truck 12 phone'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Truck 12 phone')
        ->assertJsonPath('data.display_name', 'Truck 12 phone')
        ->assertJsonPath('data.is_current', true);

    expect($current->fresh()?->name)->toBe('Truck 12 phone');
});

it('renombra otro dispositivo propio por su uuid', function (): void {
    [$token, $user] = deviceListBearer();
    $mine = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-list-mine',
        'platform' => 'android',
    ]);

    $this->withToken($token)->patchJson('/api/v1/app/devices/'.$mine->uuid, ['name' => 'Spare phone'])
        ->assertOk()
        ->assertJsonPath('data.is_current', false);

    expect($mine->fresh()?->name)->toBe('Spare phone');
});

it('no permite renombrar el dispositivo de otro usuario (404)', function (): void {
    [$token] = deviceListBearer();
    $stranger = User::factory()->create();
    $foreign = Device::create([
        'user_id' => $stranger->getKey(),
        'fingerprint' => 'fp-list-foreign',
        'platform' => 'android',
        'name' => 'Not yours',
    ]);

    $this->withToken($token)->patchJson('/api/v1/app/devices/'.$foreign->uuid, ['name' => 'Hijacked'])
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    expect($foreign->fresh()?->name)->toBe('Not yours');
});

it('valida el nombre (máximo 80 caracteres)', function (): void {
    [$token] = deviceListBearer();

    $this->withToken($token)->patchJson('/api/v1/app/devices/current', ['name' => str_repeat('a', 81)])
        ->assertStatus(422);
});
