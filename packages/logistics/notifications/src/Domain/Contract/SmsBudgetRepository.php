<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\ValueObject\SmsBudget;

/**
 * Puerta del presupuesto mensual de SMS. El dominio la define; la implementación atómica (contador por
 * período con tope) vive en Infrastructure.
 */
interface SmsBudgetRepository
{
    /**
     * Estado actual del presupuesto del período (consumo vs. tope).
     */
    public function current(string $period, int $cap): SmsBudget;

    /**
     * Consume un SMS del período de forma atómica: devuelve true si había cupo (y lo descuenta) o
     * false si el tope ya estaba alcanzado. Debe ser seguro ante concurrencia del relay.
     */
    public function consume(string $period, int $cap): bool;
}
