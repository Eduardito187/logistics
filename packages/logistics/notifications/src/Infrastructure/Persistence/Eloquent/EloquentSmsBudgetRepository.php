<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Support\Facades\DB;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\ValueObject\SmsBudget;

/**
 * Implementación Eloquent del presupuesto mensual de SMS. El consumo es atómico: bloquea la fila del
 * período (SELECT ... FOR UPDATE) dentro de una transacción, verifica el tope y recién ahí incrementa.
 * Así dos corridas concurrentes del relay nunca pasan el tope configurado.
 */
final class EloquentSmsBudgetRepository implements SmsBudgetRepository
{
    public function current(string $period, int $cap): SmsBudget
    {
        $record = SmsBudgetRecord::query()->find($period);
        $sent = $record === null ? 0 : (int) $record->sent;

        return SmsBudget::of($period, $sent, $cap);
    }

    public function consume(string $period, int $cap): bool
    {
        return DB::transaction(function () use ($period, $cap): bool {
            // Asegura la fila del período y la bloquea para el chequeo-incremento atómico.
            SmsBudgetRecord::query()->firstOrCreate(['period' => $period], ['sent' => 0]);

            /** @var SmsBudgetRecord|null $locked */
            $locked = SmsBudgetRecord::query()
                ->whereKey($period)
                ->lockForUpdate()
                ->first();

            $sent = $locked === null ? 0 : (int) $locked->sent;
            if ($sent >= $cap) {
                return false;
            }

            SmsBudgetRecord::query()->whereKey($period)->update(['sent' => $sent + 1]);

            return true;
        });
    }
}
