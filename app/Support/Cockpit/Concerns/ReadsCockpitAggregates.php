<?php

declare(strict_types=1);

namespace App\Support\Cockpit\Concerns;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use App\Support\Cockpit\CockpitStates;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lectura tolerante y cacheada para los servicios de lectura del cockpit "Control Tower".
 *
 * Misma filosofía que {@see ReadsControlTowerData}: son consultas de
 * agregación de SOLO lectura sobre el esquema (nunca modelos de dominio ni escrituras). Toleran tablas
 * o columnas ausentes: si un contexto todavía no corrió sus migraciones en este entorno, el agregado
 * degrada a 0/[] en vez de romper la landing. Los resultados primitivos (int / array) se cachean en
 * Redis por una ventana corta (< el polling del navegador) para absorber el polling a escala de
 * millones de filas; NUNCA se cachea un objeto de dominio (regla 7).
 *
 * La ventana "hoy" se calcula con {@see now()} en la zona de la plataforma (America/La_Paz), igual que
 * el resto del admin, así que el corte de medianoche es el local y no el de UTC.
 */
trait ReadsCockpitAggregates
{
    /** Vida del caché de agregados del cockpit, en segundos; por debajo del polling del panel. */
    private const COCKPIT_TTL_SECONDS = 20;

    /**
     * Cachea el resultado primitivo de un agregado bajo una clave estable. El fallo de lectura NO se
     * cachea: la excepción la atrapa el llamador, que devuelve el valor neutro y reintenta al próximo poll.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue $resolver
     * @return TValue
     */
    private function remember(string $key, \Closure $resolver): mixed
    {
        return Cache::remember('cockpit:'.$key, self::COCKPIT_TTL_SECONDS, $resolver);
    }

    /**
     * Medianoche de hoy en la zona de la plataforma (America/La_Paz).
     */
    private function startOfToday(): Carbon
    {
        return now()->startOfDay();
    }

    /**
     * Consulta base de entregas EN RIESGO: la unión entregas (ejecución) × envíos (franja comprometida).
     *
     * Predicado de "en riesgo / atrasada" (derivación honesta y única para todo el cockpit):
     *   - la entrega sigue ACTIVA: `execution_deliveries.status` NO está en {DELIVERED, FAILED}; y
     *   - su ventana comprometida ya venció: `shipments.delivery_date + delivery_window_end` < ahora.
     *
     * La ventana comprometida sale de `shipments` (delivery_date + delivery_window_end 'HH:MM'), que es
     * el join fiable por `shipment_id`. Solo se evalúan los envíos que tienen franja poblada (los demás
     * no se pueden juzgar y se excluyen). La expresión `date + time` es de Postgres (motor de la
     * plataforma); en otro motor la consulta lanza y el llamador degrada a 0/[] (tolerante).
     *
     * Devuelve un Builder SIN ejecutar; el llamador decide count()/pluck() y envuelve en try/catch.
     */
    private function atRiskDeliveriesQuery(): Builder
    {
        return DB::table('execution_deliveries as d')
            ->join('shipments as s', 's.id', '=', 'd.shipment_id')
            ->whereNotIn('d.status', CockpitStates::DELIVERY_TERMINAL)
            ->whereNotNull('s.delivery_date')
            ->whereNotNull('s.delivery_window_end')
            ->whereRaw('(s.delivery_date + s.delivery_window_end::time) < now()');
    }
}
