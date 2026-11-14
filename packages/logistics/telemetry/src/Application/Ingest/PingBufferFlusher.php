<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Ingest;

use DateTimeImmutable;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Contract\PingIngestBuffer;
use Throwable;

/**
 * Drena UNA pasada de un fragmento del buffer de ingesta y la persiste por lotes.
 *
 * Pasos:
 *  1. Reserva hasta `flush_batches` lotes del fragmento (en orden de llegada).
 *  2. Los consolida por vehículo (mismo chofer, ruta y origen): varios POST chicos del mismo vehículo se
 *     vuelven UN RecordVehiclePings, es decir un solo INSERT multi-fila de pings y una sola pasada de
 *     trip / posición en vivo / geocercas y alertas / outbox, en una transacción por vehículo.
 *  3. Confirma lo reservado. Un grupo que falla vuelve al buffer con su contador de reintentos; al
 *     agotarlo se aparta a descartes para no trabar el fragmento.
 *
 * Falla del sistema vs. falla de los datos: si en la pasada hay varios vehículos y fallan TODOS, se asume
 * la base caída: no se confirma ni se gastan reintentos, y la reserva se retoma entera en la próxima
 * pasada. Con un solo vehículo en la pasada no se puede distinguir, así que cuenta reintentos.
 *
 * La transacción es POR VEHÍCULO a propósito: pings, trip y eventos del outbox del vehículo se confirman
 * juntos, y un vehículo con datos problemáticos no arrastra a los demás.
 *
 * Idempotente de punta a punta: RecordVehiclePings descarta las lecturas ya persistidas (FixKey) y el
 * insert es ON CONFLICT DO NOTHING, así que repetir una pasada interrumpida no duplica nada.
 *
 * @phpstan-import-type BufferedBatch from PingIngestBuffer
 * @phpstan-import-type IncomingPing from RecordVehiclePings
 *
 * @phpstan-type PingGroup array{vehicle_id: string, driver_id: string|null, route_id: string|null, source: string, pings: list<IncomingPing>, batches: list<BufferedBatch>}
 */
final readonly class PingBufferFlusher
{
    public function __construct(
        private PingIngestBuffer $buffer,
        private CommandBus $commands,
    ) {}

    public function flush(int $shard): FlushReport
    {
        $batches = $this->buffer->reserve(
            $shard,
            max(1, (int) config('logistics-telemetry.ingest.buffer.flush_batches', 200)),
        );

        if ($batches === []) {
            return new FlushReport;
        }

        $groups = $this->group($batches);
        $maxBatch = max(1, (int) config('logistics-telemetry.ingest.max_batch', 500));
        $maxAttempts = max(1, (int) config('logistics-telemetry.ingest.buffer.max_attempts', 5));

        $pings = 0;
        $errors = [];
        /** @var list<PingGroup> $failedGroups */
        $failedGroups = [];

        foreach ($groups as $group) {
            try {
                $pings += $this->ingest($group, $maxBatch);
            } catch (Throwable $e) {
                $failedGroups[] = $group;
                $errors[] = sprintf('vehicle %s: %s', $group['vehicle_id'], $e->getMessage());
            }
        }

        if (count($groups) > 1 && count($failedGroups) === count($groups)) {
            // Fallaron varios vehículos y ninguno pasó: es una falla del sistema (base caída), no de los
            // datos. La reserva se deja SIN confirmar y sin gastar reintentos: la próxima pasada la retoma
            // entera, en el mismo orden, cuando la base vuelva.
            return new FlushReport(count($batches), count($groups), 0, count($failedGroups), 0, 0, $errors);
        }

        $retried = 0;
        $buried = 0;
        foreach ($failedGroups as $group) {
            foreach ($group['batches'] as $batch) {
                $batch['attempts']++;
                if ($batch['attempts'] >= $maxAttempts) {
                    $this->buffer->bury($batch);
                    $buried++;

                    continue;
                }

                $this->buffer->requeue($batch);
                $retried++;
            }
        }

        // Los reintentos ya volvieron al buffer: recién ahora se descarta lo reservado. Si el proceso muere
        // antes de esta línea, la próxima pasada reprocesa la reserva (idempotente por lectura).
        $this->buffer->acknowledge($shard);

        return new FlushReport(
            count($batches),
            count($groups),
            $pings,
            count($failedGroups),
            $retried,
            $buried,
            $errors,
        );
    }

    /**
     * Ingiere un grupo en trozos de a lo sumo `max_batch` lecturas (tope que valida RecordVehiclePings),
     * en orden cronológico. Devuelve cuántas lecturas entregó.
     *
     * @param PingGroup $group
     */
    private function ingest(array $group, int $maxBatch): int
    {
        $delivered = 0;

        foreach (array_chunk($group['pings'], $maxBatch) as $chunk) {
            $this->commands->dispatch(new RecordVehiclePings(
                vehicleId: $group['vehicle_id'],
                driverId: $group['driver_id'],
                routeId: $group['route_id'],
                source: $group['source'],
                pings: $chunk,
            ));
            $delivered += count($chunk);
        }

        return $delivered;
    }

    /**
     * Consolida los lotes por vehículo + chofer + ruta + origen (en orden de primera aparición) y ordena
     * las lecturas de cada grupo por `recorded_at`.
     *
     * @param  list<BufferedBatch> $batches
     * @return list<PingGroup>
     */
    private function group(array $batches): array
    {
        /** @var array<string, PingGroup> $groups */
        $groups = [];

        foreach ($batches as $batch) {
            $key = implode('|', [
                $batch['vehicle_id'],
                $batch['driver_id'] ?? '',
                $batch['route_id'] ?? '',
                $batch['source'],
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vehicle_id' => $batch['vehicle_id'],
                    'driver_id' => $batch['driver_id'],
                    'route_id' => $batch['route_id'],
                    'source' => $batch['source'],
                    'pings' => [],
                    'batches' => [],
                ];
            }

            $groups[$key]['batches'][] = $batch;
            foreach ($batch['pings'] as $ping) {
                $groups[$key]['pings'][] = $ping;
            }
        }

        $ordered = [];
        foreach ($groups as $group) {
            $group['pings'] = $this->chronological($group['pings']);
            $ordered[] = $group;
        }

        return $ordered;
    }

    /**
     * @param  list<IncomingPing> $pings
     * @return list<IncomingPing>
     */
    private function chronological(array $pings): array
    {
        $instants = [];
        foreach ($pings as $index => $ping) {
            try {
                $instants[$index] = (float) (new DateTimeImmutable($ping['recorded_at']))->format('U.u');
            } catch (Throwable) {
                // Instante ilegible: no se ordena acá; RecordVehiclePings lo rechaza y el lote se reintenta
                // y termina en descartes, sin trabar el resto del fragmento.
                $instants[$index] = 0.0;
            }
        }

        // Orden estable por instante (a igual instante se conserva el orden de llegada).
        uksort($pings, static fn (int $a, int $b): int => [$instants[$a], $a] <=> [$instants[$b], $b]);

        return array_values($pings);
    }
}
