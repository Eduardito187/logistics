<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Ingest;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use JsonException;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;

/**
 * Buffer de ingesta sobre listas de Valkey (protocolo redis), una lista por fragmento.
 *
 * Claves (el prefijo global de la conexión se aplica solo):
 *  - `telemetry:ingest:buffer:{n}`      lotes en espera del fragmento n (LPUSH entra, la cola sale).
 *  - `telemetry:ingest:processing:{n}`  lotes reservados por el drenado en curso (cola confiable).
 *  - `telemetry:ingest:dead`            lotes que agotaron reintentos (recuperables con `resurrect`).
 *
 * Reserva confiable: `RPOPLPUSH buffer → processing` mueve de a un lote de forma atómica; `acknowledge`
 * borra `processing`. Si el proceso muere a mitad, lo reservado sigue en `processing` y la próxima reserva
 * lo vuelve a entregar primero (entrega al-menos-una-vez; la ingesta es idempotente por lectura).
 *
 * Las listas no llevan TTL: con la política `volatile-lru` de Valkey no se evictan (igual que las colas).
 * El tope de backpressure acota su memoria. El tope se chequea con LLEN antes del LPUSH (no atómico): con
 * concurrencia puede pasarse por unos pocos lotes, lo que es inocuo para un límite de protección.
 *
 * @phpstan-import-type BufferedBatch from PingIngestBuffer
 */
final readonly class RedisPingIngestBuffer implements PingIngestBuffer
{
    private const DEFAULT_PREFIX = 'telemetry:ingest';

    public function shards(): int
    {
        return max(1, (int) config('logistics-telemetry.ingest.buffer.shards', 4));
    }

    public function shardOf(string $vehicleId): int
    {
        return crc32($vehicleId) % $this->shards();
    }

    public function push(array $batch): bool
    {
        $key = $this->bufferKey().$this->shardOf($batch['vehicle_id']);
        $redis = $this->redis();

        if ((int) $redis->command('llen', [$key]) >= $this->maxPerShard()) {
            return false;
        }

        $redis->command('lpush', [$key, $this->encode($batch)]);

        return true;
    }

    public function reserve(int $shard, int $max): array
    {
        $redis = $this->redis();
        $buffer = $this->bufferKey().$shard;
        $processing = $this->processingKey().$shard;

        // Completa la reserva hasta `$max` contando lo que quedó de una pasada interrumpida.
        $held = (int) $redis->command('llen', [$processing]);
        for ($i = $held; $i < $max; $i++) {
            $moved = $redis->command('rpoplpush', [$buffer, $processing]);
            if (!is_string($moved)) {
                break;
            }
        }

        $raw = $redis->command('lrange', [$processing, 0, -1]);
        if (!is_array($raw)) {
            return [];
        }

        // `processing` recibe por la cabeza: el índice 0 es el más nuevo. Se invierte a orden de llegada.
        $batches = [];
        foreach (array_reverse($raw) as $payload) {
            $batch = is_string($payload) ? $this->decode($payload) : null;
            if ($batch !== null) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    public function acknowledge(int $shard): void
    {
        $this->redis()->command('del', [$this->processingKey().$shard]);
    }

    public function requeue(array $batch): void
    {
        $this->redis()->command('lpush', [
            $this->bufferKey().$this->shardOf($batch['vehicle_id']),
            $this->encode($batch),
        ]);
    }

    public function bury(array $batch): void
    {
        $redis = $this->redis();
        $redis->command('lpush', [$this->deadKey(), $this->encode($batch)]);
        // Lista acotada: se conservan los descartes más recientes.
        $redis->command('ltrim', [
            $this->deadKey(),
            0,
            max(1, (int) config('logistics-telemetry.ingest.buffer.max_dead', 10000)) - 1,
        ]);
    }

    public function resurrect(int $max): int
    {
        $redis = $this->redis();
        $count = 0;

        while ($count < $max) {
            $payload = $redis->command('rpop', [$this->deadKey()]);
            if (!is_string($payload)) {
                break;
            }

            $batch = $this->decode($payload);
            if ($batch === null) {
                continue;
            }

            $batch['attempts'] = 0;
            $this->requeue($batch);
            $count++;
        }

        return $count;
    }

    public function depth(int $shard): int
    {
        $redis = $this->redis();

        return (int) $redis->command('llen', [$this->bufferKey().$shard])
            + (int) $redis->command('llen', [$this->processingKey().$shard]);
    }

    public function deadCount(): int
    {
        return (int) $this->redis()->command('llen', [$this->deadKey()]);
    }

    private function prefix(): string
    {
        $prefix = trim((string) config('logistics-telemetry.ingest.buffer.key_prefix', self::DEFAULT_PREFIX), ':');

        return $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    private function bufferKey(): string
    {
        return $this->prefix().':buffer:';
    }

    private function processingKey(): string
    {
        return $this->prefix().':processing:';
    }

    private function deadKey(): string
    {
        return $this->prefix().':dead';
    }

    private function redis(): Connection
    {
        return Redis::connection((string) config('logistics-telemetry.ingest.buffer.connection', 'default'));
    }

    /** Tope de lotes en espera por fragmento (el total configurado repartido entre los fragmentos). */
    private function maxPerShard(): int
    {
        $total = max(1, (int) config('logistics-telemetry.ingest.buffer.max_batches', 20000));

        return max(1, intdiv($total, $this->shards()));
    }

    /**
     * @param BufferedBatch $batch
     */
    private function encode(array $batch): string
    {
        return json_encode($batch, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Decodifica un lote del buffer; null si el contenido no es un lote reconocible (se descarta).
     *
     * @return BufferedBatch|null
     */
    private function decode(string $payload): ?array
    {
        try {
            $batch = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($batch)
            || !is_string($batch['vehicle_id'] ?? null)
            || !is_string($batch['source'] ?? null)
            || !is_array($batch['pings'] ?? null)) {
            return null;
        }

        $batch['driver_id'] = is_string($batch['driver_id'] ?? null) ? $batch['driver_id'] : null;
        $batch['route_id'] = is_string($batch['route_id'] ?? null) ? $batch['route_id'] : null;
        $batch['pings'] = array_values($batch['pings']);
        $batch['attempts'] = is_int($batch['attempts'] ?? null) ? $batch['attempts'] : 0;
        $batch['buffered_at'] = is_string($batch['buffered_at'] ?? null) ? $batch['buffered_at'] : '';

        /** @var BufferedBatch $batch */
        return $batch;
    }
}
