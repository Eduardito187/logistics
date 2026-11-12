<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Http\Problem\ProblemExceptionRenderer;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Lote de escaneos offline (#13): 207 con resultado por ítem (accepted | duplicate | rejected), cada ítem
 * por el mismo comando que su endpoint individual, idempotencia por ítem persistida y tope de ítems.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const SBATCH_AT = '2026-11-12T08:00:00-04:00';

/**
 * Ítem del lote con el payload dado.
 *
 * @param  array<string, mixed> $payload
 * @return array<string, mixed>
 */
function sbatchItem(string $key, string $kind, array $payload): array
{
    return ['idempotency_key' => $key, 'kind' => $kind, 'occurred_at' => SBATCH_AT, 'payload' => $payload];
}

it('acepta, deduplica y rechaza por ítem con 207', function (): void {
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    $geo = ['lat' => -16.5, 'lng' => -68.15];

    $items = [
        sbatchItem('sb-prep-1', 'preparation', ['shipment_id' => $shipment, 'vehicle_id' => $vehicle, 'unit_id' => 'U-1'] + $geo),
        sbatchItem('sb-load-1', 'load', ['shipment_id' => $shipment, 'unit_id' => 'U-1'] + $geo),
        // Repetido dentro del mismo lote → duplicate.
        sbatchItem('sb-load-1', 'load', ['shipment_id' => $shipment, 'unit_id' => 'U-1'] + $geo),
        // Inválido (sin unit_id) → rejected validation_failed.
        sbatchItem('sb-load-2', 'load', ['shipment_id' => $shipment] + $geo),
        // Regla de negocio (otro envío sin entrega abierta) → rejected business_rule_violation.
        sbatchItem('sb-load-3', 'load', ['shipment_id' => Uuid::generate()->value, 'unit_id' => 'U-9'] + $geo),
        // Salida del camión por el mismo comando que el inicio de ruta.
        sbatchItem('sb-dep-1', 'departure', ['shipment_id' => $shipment]),
    ];

    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => $items])
        ->assertStatus(207)
        ->assertJsonPath('data.0.result', 'accepted')
        ->assertJsonPath('data.1.result', 'accepted')
        ->assertJsonPath('data.2.result', 'duplicate')
        ->assertJsonPath('data.3.result', 'rejected')
        ->assertJsonPath('data.3.problem.code', 'validation_failed')
        ->assertJsonPath('data.4.result', 'rejected')
        ->assertJsonPath('data.4.problem.code', 'business_rule_violation')
        ->assertJsonPath('data.5.result', 'accepted')
        ->assertJsonPath('meta.accepted', 3)
        ->assertJsonPath('meta.duplicate', 1)
        ->assertJsonPath('meta.rejected', 2);

    expect(DB::table('execution_deliveries')->where('shipment_id', $shipment)->value('status'))->toBe('IN_TRANSIT')
        ->and(DB::table('execution_scan_batch_items')->where('idempotency_key', 'sb-load-1')->value('result'))->toBe('accepted');

    // Reenvío del lote entero: lo aceptado vuelve como duplicate, sin duplicar escaneos.
    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => array_slice($items, 0, 2)])
        ->assertStatus(207)
        ->assertJsonPath('data.0.result', 'duplicate')
        ->assertJsonPath('data.1.result', 'duplicate');

    expect(DB::table('execution_scan_events')->whereIn('idempotency_key', ['sb-prep-1', 'sb-load-1'])->count())->toBe(2);
});

it('reconoce como duplicate un escaneo ya enviado por su endpoint individual', function (): void {
    $shipment = Uuid::generate()->value;
    $body = [
        'shipment_id' => $shipment,
        'vehicle_id' => Uuid::generate()->value,
        'unit_id' => 'U-1',
        'lat' => -16.5,
        'lng' => -68.15,
        'occurred_at' => SBATCH_AT,
        'idempotency_key' => 'sb-single-1',
    ];
    test()->postJson('/api/v1/app/deliveries/scans/preparation', $body)->assertOk();

    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => [sbatchItem('sb-single-1', 'preparation', $body)]])
        ->assertStatus(207)
        ->assertJsonPath('data.0.result', 'duplicate');
});

it('rechaza con 422 un lote por encima del tope o con un tipo desconocido', function (): void {
    config(['logistics-execution.scan_batch.max_items' => 2]);
    $item = sbatchItem('sb-cap', 'departure', ['shipment_id' => Uuid::generate()->value]);

    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => [$item, $item, $item]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    test()->postJson('/api/v1/app/deliveries/scans/batch', ['items' => [sbatchItem('sb-x', 'teleport', [])]])
        ->assertStatus(422);
});

it('propaga Retry-After en las respuestas 429 de la API', function (): void {
    $request = Request::create('/api/v1/app/deliveries/scans/batch', 'POST');
    $response = app(ProblemExceptionRenderer::class)->render(
        new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]),
        $request,
    );

    expect($response)->not->toBeNull()
        ->and($response?->getStatusCode())->toBe(429)
        ->and($response?->headers->get('Retry-After'))->toBe('30');
});
