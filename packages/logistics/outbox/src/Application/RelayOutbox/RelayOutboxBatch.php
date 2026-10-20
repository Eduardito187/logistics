<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\RelayOutbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Despacha un lote del outbox de forma SINCRÓNICA (pull + despacho en el mismo proceso). Lo usa el
 * comando artisan de respaldo/depuración y los tests.
 *
 * El drenaje de producción a escala ya NO pasa por acá: el comando `logistics:outbox:pump` encola un job
 * por mensaje a las colas always-on (`outbox`/`notifications`) para que los workers despachen en paralelo
 * (C-2 de la auditoría). Este relay sincrónico se conserva como herramienta operativa y como la ruta que
 * ejercita la política de reintentos/backoff/dead-letter de forma directa.
 *
 * Toma un lote bloqueado del store (FOR UPDATE SKIP LOCKED + lease) y delega cada mensaje en
 * ProcessOutboxMessage, que aplica despacho + backoff + dead-letter. El estado se persiste mensaje por
 * mensaje: una falla a mitad del lote no pierde el progreso ya hecho.
 */
final readonly class RelayOutboxBatch
{
    private ProcessOutboxMessage $processor;

    /**
     * @param int|null             $maxAttempts    Intentos totales; null usa el valor por defecto.
     * @param array<int, int>|null $backoffMinutes Tabla de backoff; null usa la por defecto.
     */
    public function __construct(
        private OutboxStore $store,
        MessageDispatcher $dispatcher,
        private Clock $clock,
        ?int $maxAttempts = null,
        ?array $backoffMinutes = null,
    ) {
        $this->processor = new ProcessOutboxMessage($store, $dispatcher, $maxAttempts, $backoffMinutes);
    }

    /**
     * Procesa hasta $limit mensajes despachables y devuelve el resumen de la corrida.
     */
    public function handle(int $limit): RelayOutboxResult
    {
        $now = $this->clock->now();
        $messages = $this->store->pullBatch($limit, $now);

        $dispatched = 0;
        $failed = 0;
        $deadLettered = 0;

        foreach ($messages as $message) {
            $outcome = $this->processor->process($message, $now);
            $dispatched += $outcome === Outcome::Dispatched ? 1 : 0;
            $failed += $outcome === Outcome::Failed ? 1 : 0;
            $deadLettered += $outcome === Outcome::DeadLettered ? 1 : 0;
        }

        return new RelayOutboxResult(count($messages), $dispatched, $failed, $deadLettered);
    }
}
