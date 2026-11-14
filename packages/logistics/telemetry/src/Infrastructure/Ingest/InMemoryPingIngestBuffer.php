<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Ingest;

use Logistics\Telemetry\Application\Contract\PingIngestBuffer;

/**
 * Buffer de ingesta en memoria del proceso, con la misma semántica que el de Valkey (fragmentos, reserva
 * confiable, tope de backpressure, descartes). Sirve para los tests y para ejercitar el camino `buffered`
 * sin Valkey; NO es para producción: no sobrevive al proceso ni se comparte entre workers.
 *
 * @phpstan-import-type BufferedBatch from PingIngestBuffer
 */
class InMemoryPingIngestBuffer implements PingIngestBuffer
{
    /** @var array<int, list<BufferedBatch>> Lotes en espera por fragmento (orden de llegada). */
    private array $pending = [];

    /** @var array<int, list<BufferedBatch>> Lotes reservados sin confirmar por fragmento. */
    private array $processing = [];

    /** @var list<BufferedBatch> Lotes que agotaron sus reintentos. */
    private array $dead = [];

    public function __construct(
        private readonly int $shards = 1,
        private readonly int $maxPerShard = PHP_INT_MAX,
    ) {}

    public function shards(): int
    {
        return max(1, $this->shards);
    }

    public function shardOf(string $vehicleId): int
    {
        return crc32($vehicleId) % $this->shards();
    }

    public function push(array $batch): bool
    {
        $shard = $this->shardOf($batch['vehicle_id']);
        if (count($this->pending[$shard] ?? []) >= $this->maxPerShard) {
            return false;
        }

        $this->pending[$shard][] = $batch;

        return true;
    }

    public function reserve(int $shard, int $max): array
    {
        $held = $this->processing[$shard] ?? [];
        $pending = $this->pending[$shard] ?? [];

        $take = max(0, $max - count($held));
        $this->processing[$shard] = [...$held, ...array_slice($pending, 0, $take)];
        $this->pending[$shard] = array_slice($pending, $take);

        return $this->processing[$shard];
    }

    public function acknowledge(int $shard): void
    {
        $this->processing[$shard] = [];
    }

    public function requeue(array $batch): void
    {
        $this->pending[$this->shardOf($batch['vehicle_id'])][] = $batch;
    }

    public function bury(array $batch): void
    {
        $this->dead[] = $batch;
    }

    public function resurrect(int $max): int
    {
        $count = 0;
        while ($count < $max && $this->dead !== []) {
            $batch = array_shift($this->dead);
            $batch['attempts'] = 0;
            $this->requeue($batch);
            $count++;
        }

        return $count;
    }

    public function depth(int $shard): int
    {
        return count($this->pending[$shard] ?? []) + count($this->processing[$shard] ?? []);
    }

    public function deadCount(): int
    {
        return count($this->dead);
    }
}
