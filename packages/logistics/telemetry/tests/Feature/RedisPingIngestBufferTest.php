<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Logistics\Telemetry\Infrastructure\Ingest\PingBufferDrainer;
use Logistics\Telemetry\Infrastructure\Ingest\RedisPingIngestBuffer;

/**
 * El buffer de ingesta contra un Valkey REAL (el del stack): cola confiable por fragmento, reserva que
 * sobrevive a una pasada interrumpida, backpressure, descartes y reencolado. Usa un prefijo de claves
 * propio y lo borra al terminar, así no se mezcla con el buffer real de la misma instancia.
 */
const REDIS_BUFFER_TEST_PREFIX = 'test:telemetry:ingest';

/**
 * Borra las claves del prefijo de prueba (4 fragmentos como máximo en estos tests).
 */
function redisBufferTestCleanup(): void
{
    $redis = Redis::connection('default');
    foreach (range(0, 3) as $shard) {
        $redis->command('del', [REDIS_BUFFER_TEST_PREFIX.':buffer:'.$shard]);
        $redis->command('del', [REDIS_BUFFER_TEST_PREFIX.':processing:'.$shard]);
    }
    $redis->command('del', [REDIS_BUFFER_TEST_PREFIX.':dead']);
}

/**
 * ¿Hay un Valkey alcanzable en la conexión `default`?
 */
function redisBufferTestAvailable(): bool
{
    try {
        Redis::connection('default')->command('ping');

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Lote primitivo mínimo.
 *
 * @return array{vehicle_id: string, driver_id: string|null, route_id: string|null, source: string, pings: list<array<string, mixed>>, attempts: int, buffered_at: string}
 */
function redisBufferTestBatch(string $vehicleId, string $recordedAt): array
{
    return [
        'vehicle_id' => $vehicleId,
        'driver_id' => 'driver-1',
        'route_id' => null,
        'source' => 'app',
        'pings' => [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => $recordedAt, 'speed_kph' => 30.0]],
        'attempts' => 0,
        'buffered_at' => '2026-10-13T10:05:00-04:00',
    ];
}

beforeEach(function (): void {
    if (!redisBufferTestAvailable()) {
        $this->markTestSkipped('Requiere Valkey (conexión redis `default`). Correr dentro de los containers.');
    }

    config([
        'logistics-telemetry.ingest.buffer.connection' => 'default',
        'logistics-telemetry.ingest.buffer.key_prefix' => REDIS_BUFFER_TEST_PREFIX,
        'logistics-telemetry.ingest.buffer.shards' => 1,
        'logistics-telemetry.ingest.buffer.max_batches' => 20000,
        'logistics-telemetry.ingest.buffer.max_dead' => 10000,
    ]);

    redisBufferTestCleanup();
    $this->buffer = new RedisPingIngestBuffer;
});

afterEach(function (): void {
    if (redisBufferTestAvailable()) {
        redisBufferTestCleanup();
    }
});

it('entrega los lotes en orden de llegada y los descarta recién al confirmar', function (): void {
    foreach (['10:00:00', '10:00:10', '10:00:20'] as $time) {
        expect($this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T'.$time.'-04:00')))->toBeTrue();
    }

    expect($this->buffer->depth(0))->toBe(3);

    $reserved = $this->buffer->reserve(0, 10);

    expect(array_map(static fn (array $b): string => $b['pings'][0]['recorded_at'], $reserved))->toBe([
        '2026-10-13T10:00:00-04:00',
        '2026-10-13T10:00:10-04:00',
        '2026-10-13T10:00:20-04:00',
    ])
        // Reservado y sin confirmar sigue contando como profundidad.
        ->and($this->buffer->depth(0))->toBe(3);

    $this->buffer->acknowledge(0);

    expect($this->buffer->depth(0))->toBe(0)
        ->and($this->buffer->reserve(0, 10))->toBe([]);
});

it('conserva el contenido del lote (decimales, nulos y origen) al pasar por Valkey', function (): void {
    $batch = redisBufferTestBatch('veh-1', '2026-10-13T10:00:00-04:00');
    $this->buffer->push($batch);

    $reserved = $this->buffer->reserve(0, 1);

    expect($reserved)->toHaveCount(1)
        ->and($reserved[0])->toBe($batch);
});

it('vuelve a entregar lo reservado si la pasada anterior se interrumpió sin confirmar', function (): void {
    foreach (['10:00:00', '10:00:10', '10:00:20'] as $time) {
        $this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T'.$time.'-04:00'));
    }

    // Pasada interrumpida: reserva 2 y "muere" sin confirmar.
    expect($this->buffer->reserve(0, 2))->toHaveCount(2);

    // Un proceso nuevo retoma: primero lo ya reservado, en el mismo orden, y completa hasta el máximo.
    $retaken = (new RedisPingIngestBuffer)->reserve(0, 3);

    expect(array_map(static fn (array $b): string => $b['pings'][0]['recorded_at'], $retaken))->toBe([
        '2026-10-13T10:00:00-04:00',
        '2026-10-13T10:00:10-04:00',
        '2026-10-13T10:00:20-04:00',
    ]);
});

it('no reserva más que el máximo pedido y deja el resto en espera', function (): void {
    foreach (range(0, 4) as $i) {
        $this->buffer->push(redisBufferTestBatch('veh-1', sprintf('2026-10-13T10:00:%02d-04:00', $i)));
    }

    expect($this->buffer->reserve(0, 2))->toHaveCount(2);

    $this->buffer->acknowledge(0);

    expect($this->buffer->depth(0))->toBe(3);
});

it('rechaza el lote cuando el fragmento alcanzó su tope (backpressure)', function (): void {
    config(['logistics-telemetry.ingest.buffer.max_batches' => 2]);

    expect($this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:00-04:00')))->toBeTrue()
        ->and($this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:10-04:00')))->toBeTrue()
        ->and($this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:20-04:00')))->toBeFalse()
        ->and($this->buffer->depth(0))->toBe(2);
});

it('reencola un lote para reintento aunque el fragmento esté en su tope', function (): void {
    config(['logistics-telemetry.ingest.buffer.max_batches' => 1]);

    $batch = redisBufferTestBatch('veh-1', '2026-10-13T10:00:00-04:00');
    $this->buffer->push($batch);
    $this->buffer->reserve(0, 1);
    $this->buffer->acknowledge(0);

    $this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:10-04:00'));
    $this->buffer->requeue([...$batch, 'attempts' => 1]);

    expect($this->buffer->depth(0))->toBe(2);
});

it('aparta a descartes, acota la lista y reencola con los reintentos en cero', function (): void {
    config(['logistics-telemetry.ingest.buffer.max_dead' => 2]);

    foreach (['10:00:00', '10:00:10', '10:00:20'] as $time) {
        $this->buffer->bury([...redisBufferTestBatch('veh-1', '2026-10-13T'.$time.'-04:00'), 'attempts' => 5]);
    }

    // Lista acotada: quedan los 2 descartes más recientes.
    expect($this->buffer->deadCount())->toBe(2)
        ->and($this->buffer->resurrect(10))->toBe(2)
        ->and($this->buffer->deadCount())->toBe(0);

    $back = $this->buffer->reserve(0, 10);

    expect($back)->toHaveCount(2)
        ->and(array_unique(array_column($back, 'attempts')))->toBe([0]);
});

it('reparte los vehículos entre fragmentos de forma estable y los aísla entre sí', function (): void {
    config(['logistics-telemetry.ingest.buffer.shards' => 4]);

    $shard = $this->buffer->shardOf('veh-1');

    expect($this->buffer->shards())->toBe(4)
        ->and($this->buffer->shardOf('veh-1'))->toBe($shard)
        ->and($shard)->toBeGreaterThanOrEqual(0)->toBeLessThan(4);

    $this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:00-04:00'));

    foreach (range(0, 3) as $other) {
        expect($this->buffer->depth($other))->toBe($other === $shard ? 1 : 0);
    }
});

it('ignora un contenido que no es un lote reconocible en vez de romper el drenado', function (): void {
    Redis::connection('default')->command('lpush', [REDIS_BUFFER_TEST_PREFIX.':buffer:0', 'no-es-json']);
    $this->buffer->push(redisBufferTestBatch('veh-1', '2026-10-13T10:00:00-04:00'));

    expect($this->buffer->reserve(0, 10))->toHaveCount(1);
});

it('lleva un POST en modo buffered de Valkey a PostgreSQL: 202 sin persistir, y el drenado lo persiste una sola vez', function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.');
    }

    $this->withoutMiddleware();
    Queue::fake();
    config([
        'logistics-telemetry.ingest.mode' => 'buffered',
        'logistics-telemetry.live.cache_store' => 'array',
    ]);

    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000c0001';
    $payload = [
        'vehicle_id' => $vehicleId,
        'driver_id' => 'driver-1',
        'pings' => [
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'device_id' => 'dev-1'],
            ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'device_id' => 'dev-1'],
        ],
    ];

    // El mismo lote dos veces (reintento del app): los dos se aceptan y quedan en Valkey.
    foreach ([1, 2] as $attempt) {
        $this->postJson('/api/v1/app/telemetry/pings', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.buffered', true)
            ->assertJsonPath('data.duplicate_count', 0);
    }

    $pings = DB::table('telemetry_pings')->where('vehicle_id', $vehicleId);

    expect($pings->count())->toBe(0)
        ->and($this->buffer->depth(0))->toBe(2);

    $drained = app(PingBufferDrainer::class)->drain(0);

    // Persistido una sola vez (idempotente por lectura) y el buffer quedó vacío.
    expect($drained['locked'])->toBeTrue()
        ->and($pings->count())->toBe(2)
        ->and($this->buffer->depth(0))->toBe(0)
        ->and($this->buffer->deadCount())->toBe(0)
        ->and(DB::table('telemetry_trips')->where('vehicle_id', $vehicleId)->value('ping_count'))->toBe(2);
});
