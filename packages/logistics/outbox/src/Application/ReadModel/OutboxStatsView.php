<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\ReadModel;

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Modelo de lectura con el conteo de mensajes por estado del outbox. Es el insumo del tablero de
 * operación y de las alertas (p. ej. dead-letter > 0, pendientes creciendo). Forma primitiva.
 */
final readonly class OutboxStatsView
{
    public function __construct(
        public int $pending,
        public int $failed,
        public int $dispatched,
        public int $deadLettered,
    ) {}

    /**
     * @param array<string, int> $countByStatus Conteo por valor del enum de estado.
     */
    public static function fromCounts(array $countByStatus): self
    {
        return new self(
            $countByStatus[OutboxStatus::Pending->value] ?? 0,
            $countByStatus[OutboxStatus::Failed->value] ?? 0,
            $countByStatus[OutboxStatus::Dispatched->value] ?? 0,
            $countByStatus[OutboxStatus::DeadLettered->value] ?? 0,
        );
    }

    public function total(): int
    {
        return $this->pending + $this->failed + $this->dispatched + $this->deadLettered;
    }

    /**
     * Mensajes pendientes de despachar con éxito (pending + failed): la cola viva del outbox.
     */
    public function backlog(): int
    {
        return $this->pending + $this->failed;
    }

    /**
     * @return array{pending: int, failed: int, dispatched: int, dead_lettered: int, backlog: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'failed' => $this->failed,
            'dispatched' => $this->dispatched,
            'dead_lettered' => $this->deadLettered,
            'backlog' => $this->backlog(),
            'total' => $this->total(),
        ];
    }
}
