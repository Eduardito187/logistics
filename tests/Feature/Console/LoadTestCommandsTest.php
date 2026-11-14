<?php

declare(strict_types=1);

use App\Console\Commands\LoadTestPrepareCommand;
use App\Models\ApiChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

// Comandos del load test (Ola 5): loadtest:prepare siembra el pool de carga por los caminos reales de la
// aplicación (CommandBus) y loadtest:cleanup borra solo ese pool. Acá se vigilan sus tres garantías: se
// niegan en producción, el dry-run no escribe y son idempotentes. Corren contra logistics_test.

// Prefijo único: los tests de Pest viven en el scope global.
const LOADTEST_CMD_TEST_POOL = 'framework/testing/loadtest-pool-test.json';
const LOADTEST_CMD_TEST_NODE = 'CD-LTT-01';

/**
 * Conteo de las filas del pool por tabla (las mismas claves con prefijo que usa la limpieza).
 *
 * @return array<string, int>
 */
function loadTestPoolCounts(): array
{
    return [
        'users' => User::query()
            ->where('email', 'like', LoadTestPrepareCommand::EMAIL_PREFIX.'%@'.LoadTestPrepareCommand::EMAIL_DOMAIN)
            ->count(),
        'devices' => DB::table('devices')->where('fingerprint', 'like', LoadTestPrepareCommand::DEVICE_PREFIX.'%')->count(),
        'vehicles' => DB::table('fleet_vehicles')->where('plate', 'like', LoadTestPrepareCommand::PLATE_PREFIX.'%')->count(),
        'drivers' => DB::table('fleet_drivers')->where('license_number', 'like', LoadTestPrepareCommand::LICENSE_PREFIX.'%')->count(),
        'shipments' => DB::table('shipments')->where('external_id', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)->count(),
        'route_stops' => DB::table('execution_driver_route_stops')
            ->where('order_reference', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)
            ->count(),
        'deliveries' => DB::table('execution_deliveries')
            ->whereIn('shipment_id', DB::table('shipments')->where('external_id', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)->select('id'))
            ->count(),
        'trackings' => DB::table('tracking_shipments')
            ->where('order_reference', 'like', LoadTestPrepareCommand::SHIPMENT_LIKE)
            ->count(),
        'channels' => ApiChannel::query()->where('name', LoadTestPrepareCommand::CHANNEL_NAME)->count(),
    ];
}

/**
 * Opciones mínimas de una siembra chica (2 choferes x 2 paradas) que exporta el pool a storage de test.
 *
 * @return array<string, mixed>
 */
function loadTestPrepareOptions(bool $force): array
{
    return [
        '--drivers' => 2,
        '--stops' => 2,
        '--operators' => 1,
        '--logins' => 1,
        '--batch' => 'TEST01',
        '--node' => LOADTEST_CMD_TEST_NODE,
        '--output' => storage_path(LOADTEST_CMD_TEST_POOL),
        '--force' => $force,
    ];
}

beforeEach(function (): void {
    // Ningún adaptador externo (geocoder, ruteo) debe salir a la red durante la siembra.
    Http::fake();
    File::delete(storage_path(LOADTEST_CMD_TEST_POOL));
    NodeRecord::factory()->create(['code' => LOADTEST_CMD_TEST_NODE, 'lat' => -17.749, 'lng' => -63.154]);
});

afterEach(function (): void {
    File::delete(storage_path(LOADTEST_CMD_TEST_POOL));
});

it('loadtest:prepare se niega a correr en producción', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))
        ->expectsOutputToContain('refuses to run in production')
        ->assertFailed();

    expect(array_sum(loadTestPoolCounts()))->toBe(0)
        ->and(File::exists(storage_path(LOADTEST_CMD_TEST_POOL)))->toBeFalse();
});

it('loadtest:cleanup se niega a correr en producción', function (): void {
    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();
    $seeded = loadTestPoolCounts();

    $this->app['env'] = 'production';

    $this->artisan('loadtest:cleanup', ['--force' => true, '--pool' => storage_path(LOADTEST_CMD_TEST_POOL)])
        ->expectsOutputToContain('refuses to run in production')
        ->assertFailed();

    expect(loadTestPoolCounts())->toBe($seeded)
        ->and(File::exists(storage_path(LOADTEST_CMD_TEST_POOL)))->toBeTrue();
});

it('loadtest:prepare en dry-run no escribe nada', function (): void {
    $this->artisan('loadtest:prepare', loadTestPrepareOptions(false))
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(array_sum(loadTestPoolCounts()))->toBe(0)
        ->and(File::exists(storage_path(LOADTEST_CMD_TEST_POOL)))->toBeFalse();
});

it('loadtest:prepare siembra el pool y exporta el JSON que consume k6', function (): void {
    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();

    expect(loadTestPoolCounts())->toMatchArray([
        // 2 choferes + 1 operador + 1 cuenta de login.
        'users' => 4,
        // Solo choferes y operadores reciben dispositivo + token; la cuenta de login no.
        'devices' => 3,
        'vehicles' => 2,
        'drivers' => 2,
        'shipments' => 4,
        'route_stops' => 4,
        'deliveries' => 4,
        'trackings' => 4,
        'channels' => 1,
    ]);

    /** @var array<string, mixed> $pool */
    $pool = json_decode(File::get(storage_path(LOADTEST_CMD_TEST_POOL)), true, 512, JSON_THROW_ON_ERROR);

    expect($pool['drivers'])->toHaveCount(2)
        ->and($pool['operators'])->toHaveCount(1)
        ->and($pool['login_users'])->toHaveCount(1)
        ->and($pool['channel']['key'])->toStartWith('ch_')
        ->and($pool['channel']['secret'])->toStartWith('sk_');

    foreach ($pool['drivers'] as $driver) {
        expect($driver['token'])->toBeString()->not->toBe('')
            ->and($driver['vehicle_id'])->toBeString()->not->toBe('')
            ->and($driver['stops'])->toHaveCount(2);

        foreach ($driver['stops'] as $stop) {
            expect($stop['order_reference'])->toStartWith(LoadTestPrepareCommand::SHIPMENT_PREFIX.'TEST01-')
                ->and($stop['unit_ids'])->not->toBeEmpty()
                ->and($stop['tracking_token'])->toBeString()->not->toBe('');
        }
    }

    // El token exportado autentica de verdad en la puerta app (usuario + dispositivo vigente).
    $this->withToken($pool['drivers'][0]['token'])
        ->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', $pool['drivers'][0]['email']);
});

it('loadtest:prepare es idempotente: correrlo dos veces no duplica filas', function (): void {
    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();
    $first = loadTestPoolCounts();
    $tokens = DB::table('personal_access_tokens')->count();

    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();

    expect(loadTestPoolCounts())->toBe($first)
        // Los tokens se rotan (uno vigente por dispositivo), no se acumulan.
        ->and(DB::table('personal_access_tokens')->count())->toBe($tokens);
});

it('loadtest:cleanup en dry-run no borra nada', function (): void {
    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();
    $seeded = loadTestPoolCounts();

    $this->artisan('loadtest:cleanup', ['--pool' => storage_path(LOADTEST_CMD_TEST_POOL)])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(loadTestPoolCounts())->toBe($seeded)
        ->and(File::exists(storage_path(LOADTEST_CMD_TEST_POOL)))->toBeTrue();
});

it('loadtest:cleanup borra solo el pool de carga y es idempotente', function (): void {
    // Datos AJENOS al pool que la limpieza no debe tocar.
    $outsider = User::factory()->create(['email' => 'ajeno@dismac.test']);
    $nodes = DB::table('network_nodes')->count();

    $this->artisan('loadtest:prepare', loadTestPrepareOptions(true))->assertSuccessful();

    $this->artisan('loadtest:cleanup', ['--force' => true, '--pool' => storage_path(LOADTEST_CMD_TEST_POOL)])
        ->assertSuccessful();

    expect(array_sum(loadTestPoolCounts()))->toBe(0)
        ->and(User::query()->whereKey($outsider->getKey())->exists())->toBeTrue()
        ->and(DB::table('network_nodes')->count())->toBe($nodes)
        ->and(File::exists(storage_path(LOADTEST_CMD_TEST_POOL)))->toBeFalse();

    // Segunda pasada: no queda nada por borrar y termina bien.
    $this->artisan('loadtest:cleanup', ['--force' => true, '--pool' => storage_path(LOADTEST_CMD_TEST_POOL)])
        ->expectsOutputToContain('nothing to clean')
        ->assertSuccessful();
});
