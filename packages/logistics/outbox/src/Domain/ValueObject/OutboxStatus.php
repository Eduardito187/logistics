<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\ValueObject;

/**
 * Estado de un mensaje en el outbox a lo largo de su ciclo de vida.
 *
 * - Pending: persistido, todavía sin intentar despachar.
 * - Failed: se intentó despachar y falló; reintentable cuando venza availableAt (backoff).
 * - Dispatched: despachado con éxito. Estado terminal.
 * - DeadLettered: se agotaron los reintentos; requiere intervención. Estado terminal.
 *
 * Pending y Failed son "despachables": el relay los toma cuando availableAt <= now. Dispatched y
 * DeadLettered son terminales y nunca se vuelven a seleccionar.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Failed = 'failed';
    case Dispatched = 'dispatched';
    case DeadLettered = 'dead_lettered';

    /**
     * ¿Se puede (re)intentar despachar desde este estado? Verdadero solo para los no terminales.
     */
    public function isDispatchable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function isTerminal(): bool
    {
        return !$this->isDispatchable();
    }

    /**
     * Estados que el relay toma para despachar. Útil para construir la consulta del pullBatch.
     *
     * @return list<self>
     */
    public static function dispatchable(): array
    {
        return [self::Pending, self::Failed];
    }
}
