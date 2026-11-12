<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;

const APP_GATE_VERSION_OLD = '1.0.0';
const APP_GATE_VERSION_FUTURE = '9.0.0';
const APP_GATE_VERSION_MIN = '1.5.0';
const APP_GATE_ME_URL = '/api/v1/app/auth/me';

// GET /api/v1/app/app-config (configuración dictada por el servidor) y la guarda de versión mínima de la
// puerta app: cabecera X-Min-App-Version + 426 Upgrade Required por debajo de la mínima; sin X-App-Version
// el request pasa (compatibilidad hacia atrás).

function versionGateBearer(): string
{
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-version-'.uniqid(),
        'platform' => 'android',
    ]);

    $new = $user->createToken('test');
    /** @var PersonalAccessToken $accessToken */
    $accessToken = $new->accessToken;
    $accessToken->forceFill(['device_id' => $device->getKey()])->save();

    return $new->plainTextToken;
}

it('app-config es público y trae la forma completa con defaults que funcionan', function (): void {
    $response = $this->getJson('/api/v1/app/app-config');

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'platform',
            'version' => ['min_app_version', 'latest_app_version', 'update_url', 'enforced', 'min_app_versions' => ['default', 'android', 'ios', 'web']],
            'feature_flags',
            'map' => ['tile_url', 'attribution', 'max_zoom'],
            'support' => ['email', 'phone', 'whatsapp', 'hours'],
            'polling' => ['notifications_seconds', 'tasks_seconds', 'location_seconds', 'outbox_drain_seconds', 'app_config_seconds'],
            'field' => ['geofence_radius_m'],
        ]])
        ->assertJsonPath('data.version.min_app_version', null)
        ->assertHeaderMissing('X-Min-App-Version');

    expect($response->json('data.map.tile_url'))->toBeString()->not->toBeEmpty()
        ->and($response->getContent())->toContain('"feature_flags":{}');
});

it('app-config resuelve la mínima y la tienda por plataforma', function (): void {
    config()->set('app_client.version.min.default', '1.2.0');
    config()->set('app_client.version.min.android', '1.4.0');
    config()->set('app_client.version.store_url.android', 'https://play.example.test/app');
    config()->set('app_client.feature_flags', ['new_pod_flow' => true, 'beta_map' => 'false']);

    $this->getJson('/api/v1/app/app-config', ['X-App-Platform' => 'android'])
        ->assertOk()
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.version.min_app_version', '1.4.0')
        ->assertJsonPath('data.version.update_url', 'https://play.example.test/app')
        ->assertJsonPath('data.version.min_app_versions.default', '1.2.0')
        ->assertJsonPath('data.feature_flags', ['beta_map' => false, 'new_pod_flow' => true]);

    $this->getJson('/api/v1/app/app-config', ['X-App-Platform' => 'ios'])
        ->assertJsonPath('data.version.min_app_version', '1.2.0');
});

it('responde 426 problem+json cuando la versión instalada está por debajo de la mínima', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_MIN);
    config()->set('app_client.version.store_url.default', 'https://mdm.example.test/app');
    $token = versionGateBearer();

    $response = $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => '1.4.2+42']);

    $response->assertStatus(426)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-Min-App-Version', APP_GATE_VERSION_MIN)
        ->assertJsonPath('code', 'upgrade_required')
        ->assertJsonPath('min_app_version', APP_GATE_VERSION_MIN)
        ->assertJsonPath('installed_version', '1.4.2')
        ->assertJsonPath('update_url', 'https://mdm.example.test/app');
});

it('también bloquea el login público por debajo de la mínima', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_MIN);

    $this->postJson('/api/v1/app/auth/login', [], ['X-App-Version' => APP_GATE_VERSION_OLD])
        ->assertStatus(426)
        ->assertJsonPath('code', 'upgrade_required');
});

it('deja pasar requests sin X-App-Version y anuncia la mínima en la cabecera', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_MIN);
    $token = versionGateBearer();

    $this->withToken($token)->getJson(APP_GATE_ME_URL)
        ->assertOk()
        ->assertHeader('X-Min-App-Version', APP_GATE_VERSION_MIN);
});

it('deja pasar versiones iguales o mayores a la mínima', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_MIN);
    $token = versionGateBearer();

    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => APP_GATE_VERSION_MIN])->assertOk();
    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => '2.0.1'])->assertOk();
});

it('usa la mínima de la plataforma indicada en X-App-Platform', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_OLD);
    config()->set('app_client.version.min.ios', '3.0.0');
    $token = versionGateBearer();

    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => '2.0.0', 'X-App-Platform' => 'android'])
        ->assertOk();
    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => '2.0.0', 'X-App-Platform' => 'ios'])
        ->assertStatus(426)
        ->assertHeader('X-Min-App-Version', '3.0.0');
});

it('nunca rechaza app-config, así una app vieja sabe a dónde actualizarse', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_FUTURE);

    $this->getJson('/api/v1/app/app-config', ['X-App-Version' => APP_GATE_VERSION_OLD])
        ->assertOk()
        ->assertHeader('X-Min-App-Version', APP_GATE_VERSION_FUTURE);
});

it('con enforce apagado solo informa la cabecera', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_MIN);
    config()->set('app_client.version.enforce', false);
    $token = versionGateBearer();

    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => APP_GATE_VERSION_OLD])
        ->assertOk()
        ->assertHeader('X-Min-App-Version', APP_GATE_VERSION_MIN);
});

it('sin mínima configurada no agrega la cabecera ni bloquea', function (): void {
    $token = versionGateBearer();

    $this->withToken($token)->getJson(APP_GATE_ME_URL, ['X-App-Version' => '0.0.1'])
        ->assertOk()
        ->assertHeaderMissing('X-Min-App-Version');
});

it('no afecta a otras puertas', function (): void {
    config()->set('app_client.version.min.default', APP_GATE_VERSION_FUTURE);

    $this->getJson('/api/v1/health', ['X-App-Version' => APP_GATE_VERSION_OLD])
        ->assertHeaderMissing('X-Min-App-Version');
});
