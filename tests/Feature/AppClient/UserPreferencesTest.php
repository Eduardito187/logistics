<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\UserPreference;

const USER_PREFS_URL = '/api/v1/app/me/preferences';

// GET/PUT /api/v1/app/me/preferences (auditoría app #8): roundtrip, merge, borrado con null,
// last-write-wins por clave y validación por allow-list + espacio libre app.*.

/**
 * @return array{0: string, 1: User}
 */
function prefsBearer(): array
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-prefs-'.uniqid(),
        'platform' => 'android',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return [$new->plainTextToken, $user];
}

it('devuelve un objeto vacío y updated_at nulo si el usuario no tiene preferencias', function (): void {
    [$token] = prefsBearer();

    $response = $this->withToken($token)->getJson(USER_PREFS_URL);

    $response->assertOk()->assertJsonPath('data.updated_at', null);
    expect($response->getContent())->toContain('"preferences":{}');
});

it('guarda y devuelve las preferencias (roundtrip) y hace merge en el PUT', function (): void {
    [$token, $user] = prefsBearer();

    $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => [
            'ui.theme' => 'dark',
            'scan.vibrate' => false,
            'ui.home_widgets' => ['deliveries', 'scans'],
            'app.home.layout' => ['compact' => true, 'order' => [1, 2]],
        ],
    ])->assertOk()->assertJsonPath('data.ignored_keys', []);

    // Segundo PUT: agrega una clave y borra otra; las demás se conservan (merge).
    $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => ['ui.high_contrast' => true, 'scan.vibrate' => null],
    ])->assertOk();

    $data = $this->withToken($token)->getJson(USER_PREFS_URL)->assertOk()->json('data');

    // toEqual (no toBe): jsonb de Postgres reordena las claves de los objetos anidados.
    expect($data['preferences'])->toEqual([
        'app.home.layout' => ['compact' => true, 'order' => [1, 2]],
        'ui.high_contrast' => true,
        'ui.home_widgets' => ['deliveries', 'scans'],
        'ui.theme' => 'dark',
    ])->and($data['updated_at'])->toBeString();

    expect(UserPreference::query()->where('user_id', $user->getKey())->count())->toBe(1);
});

it('ignora un cambio más viejo que el último aceptado para esa clave (last-write-wins)', function (): void {
    [$token] = prefsBearer();

    $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => ['ui.theme' => 'dark'],
        'updated_at' => now()->subMinute()->toIso8601String(),
    ])->assertOk();

    // Un teléfono que estuvo offline manda un cambio de hace una hora: no pisa al más nuevo.
    $response = $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => ['ui.theme' => 'light', 'scan.sound' => true],
        'updated_at' => now()->subHour()->toIso8601String(),
    ]);

    $response->assertOk()->assertJsonPath('data.ignored_keys', ['ui.theme']);

    // Las claves llevan puntos: se leen del arreglo (data_get no escapa el punto).
    $preferences = (array) $response->json('data.preferences');
    expect($preferences['ui.theme'])->toBe('dark')
        ->and($preferences['scan.sound'])->toBeTrue();
});

it('rechaza claves desconocidas y tipos inválidos con 422 problem+json', function (): void {
    [$token] = prefsBearer();

    $response = $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => [
            'ui.theme' => 'purple',
            'scan.vibrate' => 'yes',
            'something.else' => 1,
            'ui.text_scale' => 10,
        ],
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $errors = array_keys((array) $response->json('errors'));
    expect($errors)->toContain('preferences.ui.theme')
        ->toContain('preferences.scan.vibrate')
        ->toContain('preferences.something.else')
        ->toContain('preferences.ui.text_scale');
});

it('rechaza un valor app.* demasiado grande', function (): void {
    [$token] = prefsBearer();

    $this->withToken($token)->putJson(USER_PREFS_URL, [
        'preferences' => ['app.blob' => str_repeat('x', 5000)],
    ])->assertStatus(422);
});

it('exige autenticación', function (): void {
    $this->getJson(USER_PREFS_URL)->assertUnauthorized();
});
