<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\RelayOutbox;

use DateInterval;
use DateTimeImmutable;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Procesa UN mensaje del outbox: lo despacha a su destino externo y persiste el resultado (despachado,
 * fallido con backoff, o dead-letter al agotar los intentos). Es el núcleo reutilizable del despacho,
 * compartido por el relay sincrónico (RelayOutboxBatch) y por el job encolado por mensaje (C-2 del
 * rediseño de throughput): ambos aplican EXACTAMENTE la misma política de reintentos/backoff/dead-letter.
 *
 * La idempotencia la garantizan aguas arriba la clave event_id del store y, aguas abajo, los consumidores
 * (que se desduplican por eventId): reprocesar un mensaje es inocuo. Si el mensaje ya quedó en un estado
 * terminal (p. ej. otro worker lo despachó entre el pull y este proceso), no se vuelve a despachar.
 */
final readonly class ProcessOutboxMessage
{
    /** Cantidad total de intentos antes de descartar el mensaje (dead letter), por defecto. */
    public const DEFAULT_MAX_ATTEMPTS = 6;

    /**
     * Minutos de espera antes del próximo intento, por número de intento ya realizado (por defecto).
     * Backoff exponencial: 1 → 1min, 2 → 5min, 3 → 30min, 4 → 2h, 5 → 6h.
     *
     * @var array<int, int>
     */
    public const DEFAULT_BACKOFF_MINUTES = [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 360];

    private int $maxAttempts;

    /** @var array<int, int> */
    private array $backoffMinutes;

    /**
     * @param int|null             $maxAttempts    Intentos totales; null usa el valor por defecto.
     * @param array<int, int>|null $backoffMinutes Tabla de backoff; null usa la por defecto.
     */
    public function __construct(
        private OutboxStore $store,
        private MessageDispatcher $dispatcher,
        ?int $maxAttempts = null,
        ?array $backoffMinutes = null,
    ) {
        $this->maxAttempts = $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS;
        $this->backoffMinutes = $backoffMinutes ?? self::DEFAULT_BACKOFF_MINUTES;
    }

    /**
     * Despacha el mensaje y persiste su nuevo estado. Devuelve el desenlace para contabilizar.
     *
     * Si el mensaje ya está en estado terminal (despachado/descartado), no hace nada y lo informa como
     * ya despachado: protege de reprocesos (el job puede reencolarse si venció el lease del store).
     */
    public function process(OutboxMessage $message, DateTimeImmutable $now): Outcome
    {
        if ($message->status()->isTerminal()) {
            return Outcome::Dispatched;
        }

        try {
            $this->dispatcher->dispatch($message);
            $message->markDispatched($now);
            $this->store->save($message);

            return Outcome::Dispatched;
        } catch (Throwable $error) {
            return $this->handleFailure($message, $error, $now);
        }
    }

    private function handleFailure(OutboxMessage $message, Throwable $error, DateTimeImmutable $now): Outcome
    {
        $attemptJustMade = $message->attempts() + 1;
        $reason = $error->getMessage();

        if ($attemptJustMade >= $this->maxAttempts) {
            $message->markDeadLettered($reason);
            $this->store->save($message);

            return Outcome::DeadLettered;
        }

        $message->markFailed($reason, $this->nextAttemptAt($now, $attemptJustMade));
        $this->store->save($message);

        return Outcome::Failed;
    }

    /**
     * Calcula cuándo reintentar según el número de intento ya realizado (backoff exponencial).
     */
    private function nextAttemptAt(DateTimeImmutable $now, int $attemptJustMade): DateTimeImmutable
    {
        // Fallback 360 (el último escalón) por si se agrega un intento intermedio sin su tabla.
        $minutes = $this->backoffMinutes[$attemptJustMade] ?? 360;

        return $now->add(new DateInterval("PT{$minutes}M"));
    }
}
