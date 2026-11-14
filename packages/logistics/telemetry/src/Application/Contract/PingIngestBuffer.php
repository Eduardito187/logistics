<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;

/**
 * Puerto del BUFFER de ingesta: cola intermedia donde el request deja el lote de lecturas (forma primitiva)
 * para que un worker lo persista después. La implementación real vive en Infrastructure (Valkey).
 *
 * El buffer está FRAGMENTADO por vehículo (`shardOf`): cada fragmento lo drena un solo proceso a la vez,
 * así las lecturas de un vehículo nunca se procesan en paralelo y conservan su orden de llegada.
 *
 * Entrega al-menos-una-vez: `reserve` no borra lo que entrega; recién `acknowledge` lo descarta. Si el
 * proceso muere a mitad, la siguiente reserva vuelve a entregar lo mismo (la ingesta es idempotente por
 * lectura, así que repetir es seguro).
 *
 * @phpstan-import-type IncomingPing from RecordVehiclePings
 *
 * @phpstan-type BufferedBatch array{vehicle_id: string, driver_id: string|null, route_id: string|null, source: string, pings: list<IncomingPing>, attempts: int, buffered_at: string}
 */
interface PingIngestBuffer
{
    /** Cantidad de fragmentos del buffer. */
    public function shards(): int;

    /** Fragmento que le corresponde a un vehículo (estable para el mismo id). */
    public function shardOf(string $vehicleId): int;

    /**
     * Encola un lote. Devuelve false si el fragmento alcanzó su tope (backpressure): el lote NO se encoló.
     * Lanza si el buffer no está disponible.
     *
     * @param BufferedBatch $batch
     */
    public function push(array $batch): bool;

    /**
     * Reserva hasta `$max` lotes del fragmento, en orden de llegada. Incluye los reservados y aún no
     * confirmados de una pasada anterior interrumpida.
     *
     * @return list<BufferedBatch>
     */
    public function reserve(int $shard, int $max): array;

    /** Confirma (descarta) lo reservado en el fragmento. */
    public function acknowledge(int $shard): void;

    /**
     * Devuelve un lote al buffer para reintentarlo (sin aplicar el tope de backpressure).
     *
     * @param BufferedBatch $batch
     */
    public function requeue(array $batch): void;

    /**
     * Aparta un lote que agotó sus reintentos a la lista de descartes (recuperable a mano).
     *
     * @param BufferedBatch $batch
     */
    public function bury(array $batch): void;

    /** Reencola hasta `$max` lotes de la lista de descartes con sus reintentos en cero. Devuelve cuántos. */
    public function resurrect(int $max): int;

    /** Profundidad del fragmento: lotes en espera más los reservados sin confirmar. */
    public function depth(int $shard): int;

    /** Lotes en la lista de descartes. */
    public function deadCount(): int;
}
