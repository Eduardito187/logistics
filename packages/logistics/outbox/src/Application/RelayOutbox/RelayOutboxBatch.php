<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\RelayOutbox;

use DateInterval;
use DateTimeImmutable;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * Despacha un lote del outbox. Es el corazón del worker: lo invoca el comando artisan (o el scheduler).
 *
 * Toma un lote bloqueado del store y, por cada mensaje, invoca al MessageDispatcher. Si el despacho
 * sale bien, marca el mensaje como despachado. Si falla, aplica backoff exponencial y lo reprograma;
 * al agotar los intentos, lo manda a dead letter. El estado se persiste mensaje por mensaje, así una
 * falla a mitad del lote no pierde el progreso ya hecho.
 */
final readonly class RelayOutboxBatch
{
    /** Cantidad total de intentos antes de descartar el mensaje (dead letter), por defecto. */
    private const DEFAULT_MAX_ATTEMPTS = 6;

    /**
     * Minutos de espera antes del próximo intento, por número de intento ya realizado (por defecto).
     * Backoff exponencial: 1 → 1min, 2 → 5min, 3 → 30min, 4 → 2h, 5 → 6h.
     *
     * @var array<int, int>
     */
    private const DEFAULT_BACKOFF_MINUTES = [1 => 1, 2 => 5, 3 => 30, 4 => 120, 5 => 360];

    /** Intentos totales antes del dead-letter (config logistics-outbox.relay.max_attempts). */
    private int $maxAttempts;

    /**
     * Backoff efectivo (config logistics-outbox.relay.backoff_minutes).
     *
     * @var array<int, int>
     */
    private array $backoffMinutes;

    /**
     * @param int|null             $maxAttempts    Intentos totales; null usa el valor por defecto.
     * @param array<int, int>|null $backoffMinutes Tabla de backoff; null usa la por defecto.
     */
    public function __construct(
        private OutboxStore $store,
        private MessageDispatcher $dispatcher,
        private Clock $clock,
        ?int $maxAttempts = null,
        ?array $backoffMinutes = null,
    ) {
        $this->maxAttempts = $maxAttempts ?? self::DEFAULT_MAX_ATTEMPTS;
        $this->backoffMinutes = $backoffMinutes ?? self::DEFAULT_BACKOFF_MINUTES;
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
            $outcome = $this->process($message, $now);
            $dispatched += $outcome === Outcome::Dispatched ? 1 : 0;
            $failed += $outcome === Outcome::Failed ? 1 : 0;
            $deadLettered += $outcome === Outcome::DeadLettered ? 1 : 0;
        }

        return new RelayOutboxResult(count($messages), $dispatched, $failed, $deadLettered);
    }

    private function process(OutboxMessage $message, DateTimeImmutable $now): Outcome
    {
        try {
            $this->dispatcher->dispatch($message);
            $message->markDispatched($now);
            $this->store->save($message);

            return Outcome::Dispatched;
        } catch (Throwable $e) {
            return $this->handleFailure($message, $e, $now);
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
