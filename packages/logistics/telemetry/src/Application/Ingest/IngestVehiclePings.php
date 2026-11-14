<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Ingest;

use DateTimeImmutable;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Contract\LivePositionHotState;
use Logistics\Telemetry\Application\Contract\PingFlushScheduler;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Throwable;

/**
 * Puerta de entrada de la ingesta de pings: decide el camino según `ingest.mode`.
 *
 *  - `sync`: despacha RecordVehiclePings por el CommandBus (transacción del request), como siempre.
 *  - `buffered`: deja el lote en el buffer (Valkey), programa el drenado del fragmento y refresca la última
 *    posición caliente. El request NO toca PostgreSQL; la persistencia y lo derivado (trips, posición en
 *    vivo, geocercas/alertas, outbox) los hace el worker con el mismo RecordVehiclePings.
 *
 * No pasa por el CommandBus a propósito: el decorador transaccional abriría una transacción de base por
 * cada POST, que es justo el costo que el modo `buffered` evita.
 *
 * Garantías del modo `buffered`:
 *  - Backpressure: con el fragmento lleno el lote se rechaza (el controller responde 429 + Retry-After).
 *  - Buffer caído: cae al camino `sync` (configurable) para no perder lecturas.
 *  - Programar el drenado y refrescar el estado caliente son best-effort: si fallan, el lote ya quedó
 *    encolado y el drenado de respaldo del scheduler lo toma.
 */
final readonly class IngestVehiclePings
{
    public function __construct(
        private CommandBus $commands,
        private PingIngestBuffer $buffer,
        private PingFlushScheduler $scheduler,
        private LivePositionHotState $hotState,
        private Clock $clock,
    ) {}

    /** ¿La ingesta está configurada para ir por el buffer? */
    public function buffers(): bool
    {
        return config('logistics-telemetry.ingest.mode', IngestReceipt::MODE_SYNC) === IngestReceipt::MODE_BUFFERED;
    }

    public function ingest(RecordVehiclePings $command): IngestReceipt
    {
        if (!$this->buffers() || $command->pings === []) {
            $this->commands->dispatch($command);

            return IngestReceipt::stored();
        }

        try {
            $pushed = $this->buffer->push([
                'vehicle_id' => $command->vehicleId,
                'driver_id' => $command->driverId,
                'route_id' => $command->routeId,
                'source' => $command->source,
                'pings' => $command->pings,
                'attempts' => 0,
                'buffered_at' => $this->clock->now()->format(DateTimeImmutable::ATOM),
            ]);
        } catch (Throwable $e) {
            if (!(bool) config('logistics-telemetry.ingest.buffer.fallback_to_sync', true)) {
                throw $e;
            }

            // Buffer no disponible: se persiste en el request para no perder el lote.
            $this->commands->dispatch($command);

            return IngestReceipt::stored();
        }

        if (!$pushed) {
            return IngestReceipt::rejected((int) config('logistics-telemetry.ingest.buffer.retry_after_seconds', 15));
        }

        $this->afterBuffered($command);

        return IngestReceipt::buffered();
    }

    /** Programa el drenado del fragmento y adelanta la última posición caliente. Nunca rompe el acuse. */
    private function afterBuffered(RecordVehiclePings $command): void
    {
        try {
            $this->scheduler->schedule($this->buffer->shardOf($command->vehicleId));
        } catch (Throwable) {
            // El drenado de respaldo del scheduler (cada minuto) toma lo que quede sin programar.
        }

        try {
            $this->touchHotState($command);
        } catch (Throwable) {
            // El estado caliente es un acelerador: el procesamiento del lote lo vuelve a escribir.
        }
    }

    /** Lleva al estado caliente la lectura más reciente del lote (por `recorded_at`). */
    private function touchHotState(RecordVehiclePings $command): void
    {
        $latest = null;
        $latestAt = null;

        foreach ($command->pings as $ping) {
            $at = new DateTimeImmutable($ping['recorded_at']);
            if ($latestAt === null || $at > $latestAt) {
                $latest = $ping;
                $latestAt = $at;
            }
        }

        if ($latest === null || $latestAt === null) {
            return;
        }

        $this->hotState->touch(
            $command->vehicleId,
            $latest['lat'],
            $latest['lng'],
            $latest['speed_kph'] ?? null,
            $latest['ignition'] ?? null,
            $latestAt,
        );
    }
}
