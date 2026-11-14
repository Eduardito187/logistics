<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Logistics\Telemetry\Application\Ingest\PingBufferFlusher;
use Logistics\Telemetry\Infrastructure\Ingest\InMemoryPingIngestBuffer;
use Logistics\Telemetry\Infrastructure\Ingest\PingBufferDrainer;
use Logistics\Telemetry\Infrastructure\LiveState\LiveVehicleState;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;
use Logistics\Telemetry\Infrastructure\Queue\FlushPingBufferJob;

/**
 * Ingesta dedicada de pings en modo `buffered` (Ola 6): el POST valida y encola sin tocar PostgreSQL, y el
 * drenado persiste por lotes, idempotente, con reintentos y descartes. Se usa el buffer en memoria (misma
 * semántica que el de Valkey) y el store de cache `array`, así no hace falta Valkey. Los controllers se
 * ejercitan por sus rutas reales omitiendo el middleware de puerta (auth/device), como el resto de la suite.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();

    config([
        'logistics-telemetry.ingest.mode' => 'buffered',
        'logistics-telemetry.live.cache_store' => 'array',
    ]);

    $this->vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000b0001';
    $this->buffer = new InMemoryPingIngestBuffer;
    app()->instance(PingIngestBuffer::class, $this->buffer);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Lote primitivo tal como lo deja el request en el buffer.
 *
 * @param  list<array<string, mixed>> $pings
 * @return array<string, mixed>
 */
function bufferedBatch(string $vehicleId, array $pings): array
{
    return [
        'vehicle_id' => $vehicleId,
        'driver_id' => 'driver-1',
        'route_id' => 'route-1',
        'source' => 'app',
        'pings' => $pings,
        'attempts' => 0,
        'buffered_at' => '2026-10-13T10:05:00-04:00',
    ];
}

it('encola el lote, responde 202 sin persistir y programa el drenado en la cola telemetry', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => $this->vehicleId,
        'driver_id' => 'driver-1',
        'pings' => [
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'device_id' => 'dev-1'],
            ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'device_id' => 'dev-1'],
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.buffered', true)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.accepted_count', 2)
        ->assertJsonPath('data.duplicate_count', 0);

    // El request no tocó la base: nada persistido todavía; el lote espera en el buffer.
    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(0)
        ->and(VehiclePositionRecord::query()->find($this->vehicleId))->toBeNull()
        ->and($this->buffer->depth(0))->toBe(1);

    Queue::assertPushedOn('telemetry', FlushPingBufferJob::class);
});

it('programa un solo drenado por fragmento aunque lleguen varios lotes en la ventana de flush', function (): void {
    Queue::fake();

    foreach (['10:00:00', '10:00:10', '10:00:20'] as $time) {
        $this->postJson('/api/v1/app/telemetry/pings', [
            'vehicle_id' => $this->vehicleId,
            'pings' => [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => "2026-10-13T{$time}-04:00"]],
        ])->assertStatus(202);
    }

    expect($this->buffer->depth(0))->toBe(3);
    Queue::assertPushed(FlushPingBufferJob::class, 1);
});

it('adelanta la última posición caliente en el momento de la ingesta', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => $this->vehicleId,
        'pings' => [
            ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0],
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 0.0],
        ],
    ])->assertStatus(202);

    // Toma la lectura MÁS RECIENTE del lote (no la última en el orden del cuerpo).
    $hot = app(LiveVehicleState::class)->get($this->vehicleId);

    expect($hot)->not->toBeNull()
        ->and($hot['lat'])->toBe(-16.501)
        ->and($hot['status'])->toBe('moving');
});

it('con la cola sync el job drena el buffer y deja pings, posición viva y trip', function (): void {
    // QUEUE_CONNECTION=sync en los tests: el job de drenado corre al despacharse.
    $this->postJson('/api/v1/integrations/telemetry/pings', [
        'vehicle_id' => $this->vehicleId,
        'pings' => [
            ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'ignition' => true],
            ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'ignition' => true],
        ],
    ])->assertStatus(202)->assertJsonPath('data.source', 'integration');

    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(2)
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->where('source', 'integration')->count())->toBe(2)
        ->and(VehiclePositionRecord::query()->find($this->vehicleId))->not->toBeNull()
        ->and(TripRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1)
        ->and($this->buffer->depth(0))->toBe(0);
});

it('el drenado consolida los lotes de un vehículo y es idempotente por lectura', function (): void {
    $first = [
        ['lat' => -16.500, 'lng' => -68.150, 'recorded_at' => '2026-10-13T10:00:00-04:00', 'speed_kph' => 30.0, 'device_id' => 'dev-1'],
        ['lat' => -16.501, 'lng' => -68.151, 'recorded_at' => '2026-10-13T10:01:00-04:00', 'speed_kph' => 35.0, 'device_id' => 'dev-1'],
    ];
    // El segundo lote repite una lectura del primero (reintento del app) y trae una nueva.
    $second = [
        $first[1],
        ['lat' => -16.502, 'lng' => -68.152, 'recorded_at' => '2026-10-13T10:02:00-04:00', 'speed_kph' => 40.0, 'device_id' => 'dev-1'],
    ];

    $this->buffer->push(bufferedBatch($this->vehicleId, $first));
    $this->buffer->push(bufferedBatch($this->vehicleId, $second));

    $report = app(PingBufferFlusher::class)->flush(0);

    expect($report->batches)->toBe(2)
        ->and($report->groups)->toBe(1)
        ->and($report->failedGroups)->toBe(0)
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(3)
        ->and(TripRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1)
        ->and($this->buffer->depth(0))->toBe(0);

    // Reprocesar los mismos lotes (pasada interrumpida, reintento) no duplica lecturas ni trips.
    $this->buffer->push(bufferedBatch($this->vehicleId, $first));
    $this->buffer->push(bufferedBatch($this->vehicleId, $second));
    app(PingBufferFlusher::class)->flush(0);

    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(3)
        ->and(TripRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1);
});

it('el drenado separa por vehículo: uno con datos inválidos no arrastra al otro', function (): void {
    $other = '0192aaaa-bbbb-7ccc-8ddd-0000000b0002';
    config(['logistics-telemetry.ingest.buffer.max_attempts' => 2]);

    $this->buffer->push(bufferedBatch($this->vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));
    // Latitud fuera de rango: el dominio la rechaza al persistir.
    $this->buffer->push(bufferedBatch($other, [
        ['lat' => 999.0, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));

    $report = app(PingBufferFlusher::class)->flush(0);

    expect($report->groups)->toBe(2)
        ->and($report->failedGroups)->toBe(1)
        ->and($report->retriedBatches)->toBe(1)
        ->and($report->stalled())->toBeFalse()
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1)
        ->and(PingRecord::query()->where('vehicle_id', $other)->count())->toBe(0);

    // Segundo intento: agota los reintentos y se aparta a descartes, sin trabar el fragmento.
    $report = app(PingBufferFlusher::class)->flush(0);

    expect($report->buriedBatches)->toBe(1)
        ->and($this->buffer->deadCount())->toBe(1)
        ->and($this->buffer->depth(0))->toBe(0);
});

it('si fallan varios vehículos a la vez (base caída) retiene la reserva sin gastar reintentos', function (): void {
    $other = '0192aaaa-bbbb-7ccc-8ddd-0000000b0003';
    $failing = new class implements CommandBus
    {
        public function dispatch(Command $command): void
        {
            throw new RuntimeException('database is down');
        }
    };

    foreach ([$this->vehicleId, $other] as $vehicleId) {
        $this->buffer->push(bufferedBatch($vehicleId, [
            ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
        ]));
    }

    $report = (new PingBufferFlusher($this->buffer, $failing))->flush(0);

    expect($report->stalled())->toBeTrue()
        ->and($report->retriedBatches)->toBe(0)
        ->and($report->buriedBatches)->toBe(0)
        ->and($this->buffer->depth(0))->toBe(2)
        ->and($this->buffer->deadCount())->toBe(0);

    // Cuando la base vuelve, la misma reserva se procesa entera y con sus reintentos intactos.
    $report = app(PingBufferFlusher::class)->flush(0);

    expect($report->failedGroups)->toBe(0)
        ->and(PingRecord::query()->whereIn('vehicle_id', [$this->vehicleId, $other])->count())->toBe(2)
        ->and($this->buffer->depth(0))->toBe(0);
});

it('una pasada de un solo vehículo que falla cuenta reintentos y conserva el lote', function (): void {
    $failing = new class implements CommandBus
    {
        public function dispatch(Command $command): void
        {
            throw new RuntimeException('database is down');
        }
    };

    $this->buffer->push(bufferedBatch($this->vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));

    $report = (new PingBufferFlusher($this->buffer, $failing))->flush(0);

    expect($report->stalled())->toBeTrue()
        ->and($report->retriedBatches)->toBe(1)
        ->and($report->errors)->toHaveCount(1)
        ->and($this->buffer->depth(0))->toBe(1)
        ->and($this->buffer->deadCount())->toBe(0);
});

it('la reserva sin confirmar se vuelve a entregar (pasada interrumpida)', function (): void {
    $this->buffer->push(bufferedBatch($this->vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));

    $first = $this->buffer->reserve(0, 10);
    $again = $this->buffer->reserve(0, 10);

    expect($first)->toHaveCount(1)
        ->and($again)->toBe($first);

    $this->buffer->acknowledge(0);

    expect($this->buffer->reserve(0, 10))->toBe([]);
});

it('aplica backpressure: con el buffer lleno responde 429 con Retry-After y no toma el lote', function (): void {
    Queue::fake();
    config(['logistics-telemetry.ingest.buffer.retry_after_seconds' => 7]);

    $buffer = new InMemoryPingIngestBuffer(1, 1);
    app()->instance(PingIngestBuffer::class, $buffer);

    $payload = [
        'vehicle_id' => $this->vehicleId,
        'pings' => [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00']],
    ];

    $this->postJson('/api/v1/app/telemetry/pings', $payload)->assertStatus(202);

    $this->postJson('/api/v1/app/telemetry/pings', $payload)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '7')
        ->assertJsonPath('code', 'ingest_backpressure')
        ->assertJsonPath('error.details.retry_after_seconds', 7);

    expect($buffer->depth(0))->toBe(1);
});

it('si el buffer no responde cae al camino sync y persiste en el request', function (): void {
    $broken = new class extends InMemoryPingIngestBuffer
    {
        public function push(array $batch): bool
        {
            throw new RuntimeException('valkey is down');
        }
    };
    app()->instance(PingIngestBuffer::class, $broken);

    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => $this->vehicleId,
        'pings' => [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00']],
    ])->assertStatus(202)->assertJsonMissingPath('data.buffered');

    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1);
});

it('el drenado va bajo candado por fragmento: con el candado tomado no procesa', function (): void {
    $this->buffer->push(bufferedBatch($this->vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));

    $held = Cache::lock('telemetry:ingest:flush-lock:0', 60);
    expect($held->get())->toBeTrue();

    $blocked = app(PingBufferDrainer::class)->drain(0);

    expect($blocked['locked'])->toBeFalse()
        ->and($blocked['remaining'])->toBe(1)
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(0);

    $held->release();

    $drained = app(PingBufferDrainer::class)->drain(0);

    expect($drained['locked'])->toBeTrue()
        ->and($drained['pings'])->toBe(1)
        ->and($drained['remaining'])->toBe(0)
        ->and(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1);
});

it('el comando de respaldo drena lo que haya quedado en el buffer', function (): void {
    $this->buffer->push(bufferedBatch($this->vehicleId, [
        ['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00'],
    ]));

    $this->artisan('telemetry:flush-ping-buffer')->assertSuccessful();

    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1)
        ->and($this->buffer->depth(0))->toBe(0);
});

it('en modo sync (default) el POST persiste en el request y no usa el buffer', function (): void {
    config(['logistics-telemetry.ingest.mode' => 'sync']);

    $this->postJson('/api/v1/app/telemetry/pings', [
        'vehicle_id' => $this->vehicleId,
        'pings' => [['lat' => -16.5, 'lng' => -68.15, 'recorded_at' => '2026-10-13T10:00:00-04:00']],
    ])->assertStatus(202)->assertJsonMissingPath('data.buffered');

    expect(PingRecord::query()->where('vehicle_id', $this->vehicleId)->count())->toBe(1)
        ->and($this->buffer->depth(0))->toBe(0);
});
