<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Queue;

use Logistics\Capacity\Domain\Contract\CapacityActivationDispatcher;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Implementación del puerto de activación sobre la cola de Laravel (Valkey). Encola el primer lote de la
 * activación DESPUÉS del commit de la transacción del comando (afterCommit), para que el worker nunca
 * arranque sobre un horizonte que todavía no se confirmó en base.
 */
final readonly class QueuedCapacityActivationDispatcher implements CapacityActivationDispatcher
{
    /**
     * @param list<int>              $weekdays
     * @param list<ActivationWindow> $windows
     */
    public function dispatch(
        string $municipalityId,
        SlotDate $from,
        SlotDate $to,
        array $weekdays,
        array $windows,
    ): void {
        $rawWindows = array_map(static fn (ActivationWindow $w): array => $w->toArray(), $windows);

        ActivateCapacityWindowJob::dispatch(
            $municipalityId,
            $from->toString(),
            $to->toString(),
            array_values($weekdays),
            $rawWindows,
        )->afterCommit();
    }
}
