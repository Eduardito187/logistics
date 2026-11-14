<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * El actor del escaneo (dispositivo + usuario) se resuelve del TOKEN Sanctum, no del cuerpo: la app no
 * manda device_id/user_id y el dominio (DeviceContext) los exige no vacíos. Sin esta resolución, TODO
 * escaneo (preparación / carga / descarga / POD / recepción) falla con "el id del dispositivo no puede
 * estar vacío". Se ejercita el escaneo de preparación, que pasa por ExecutionController como todos.
 */
beforeEach(function (): void {
    // auth:sanctum SIGUE activo (es lo que puebla currentAccessToken con el device del token); solo se
    // apagan los guardas que no se están probando.
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('resuelve device y user del token Sanctum cuando el cuerpo no los trae', function (): void {
    $user = User::factory()->create();
    $device = Device::create([
        'user_id' => $user->getKey(),
        'fingerprint' => 'fp-'.uniqid(),
        'platform' => 'android',
    ]);
    $issued = $user->createToken('app');
    /** @var PersonalAccessToken $token */
    $token = $issued->accessToken;
    $token->forceFill(['device_id' => $device->getKey()])->save();

    $shipment = Uuid::generate()->value;

    // SIN device_id ni user_id en el cuerpo: el app real tampoco los manda.
    $this->withToken($issued->plainTextToken)
        ->postJson('/api/v1/app/deliveries/scans/preparation', [
            'shipment_id' => $shipment,
            'unit_id' => 'U-1',
            'vehicle_id' => Uuid::generate()->value,
            'lat' => -17.78,
            'lng' => -63.18,
            'occurred_at' => '2026-11-12T09:00:00+00:00',
            'idempotency_key' => Uuid::generate()->value,
        ])
        ->assertOk();

    $scan = DB::table('execution_scan_events as e')
        ->join('execution_deliveries as d', 'd.id', '=', 'e.delivery_id')
        ->where('d.shipment_id', $shipment)
        ->where('e.type', 'PREPARATION')
        ->first(['e.device_id', 'e.user_id']);

    // El escaneo quedó con el actor del token, no vacío.
    expect($scan)->not->toBeNull()
        ->and((string) $scan->device_id)->toBe((string) $device->getKey())
        ->and((string) $scan->user_id)->toBe((string) $user->getKey());
});
