<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Enums\Role;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lectura directa (solo lectura) de las tablas para los widgets de la torre de control.
 *
 * Todos los conteos toleran tablas ausentes: si un contexto todavía no corrió sus migraciones
 * (o su tabla no existe en este entorno) se devuelve cero en vez de romper el tablero. Nunca se
 * escribe ni se instancian modelos de dominio: son consultas de agregación sobre el esquema.
 *
 * Los agregados (COUNT(*)/GROUP BY) se cachean en Redis por {@see self::KPI_TTL_SECONDS} segundos
 * (auditoría A-1): sobre millones de filas un COUNT sin filtro es un scan completo, y con varios
 * supervisores pollando cada 30s eso es carga sostenida. El TTL (< el polling) absorbe el polling y
 * mantiene el número "fresco de un vistazo". Se cachea SIEMPRE la forma primitiva (int / array de
 * int), nunca objetos de dominio (regla 7). Las claves son estables por consulta y se comparten
 * entre widgets que piden el mismo agregado (p. ej. dead-letter del outbox).
 */
trait ReadsControlTowerData
{
    /** Vida del caché de KPIs, en segundos; por debajo del polling de 30s para absorberlo. */
    private const KPI_TTL_SECONDS = 30;

    /**
     * Cachea el resultado primitivo de un agregado bajo una clave estable; el fallo de lectura NO se
     * cachea (la excepción sube al llamador, que devuelve el valor neutro y reintenta al próximo poll).
     *
     * @template TValue
     *
     * @param  \Closure(): TValue $resolver
     * @return TValue
     */
    private function rememberKpi(string $key, \Closure $resolver): mixed
    {
        return Cache::remember('control-tower:'.$key, self::KPI_TTL_SECONDS, $resolver);
    }

    /**
     * Los widgets de la torre de control son KPIs de toda la plataforma: solo los ven las cuentas
     * con alcance operativo global (administrador o supervisor), igual que {@see PlatformStatsWidget}.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Conteo total de una tabla; cero si la tabla no existe.
     */
    protected function count(string $table): int
    {
        try {
            return $this->rememberKpi(
                'count:'.$table,
                static fn (): int => DB::table($table)->count(),
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Conteo de filas con una columna igual a un valor; cero si la tabla no existe.
     */
    protected function whereCount(string $table, string $column, string $value): int
    {
        try {
            return $this->rememberKpi(
                'whereCount:'.$table.':'.$column.':'.$value,
                static fn (): int => DB::table($table)->where($column, $value)->count(),
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Conteo agrupado por `status`, opcionalmente acotado a las filas de hoy por `$sinceColumn`.
     *
     * La ventana "hoy" se calcula con {@see now()} en la zona de la plataforma (America/La_Paz),
     * así que el corte de medianoche es el local y no el de UTC.
     *
     * @return array<string, int>
     */
    protected function countByStatus(string $table, ?string $sinceColumn = null): array
    {
        try {
            // La ventana "hoy" entra en la clave para no mezclar días al cruzar medianoche.
            $since = $sinceColumn !== null ? $this->startOfToday() : null;
            $key = 'countByStatus:'.$table.':'.($sinceColumn ?? '-')
                .($since !== null ? ':'.$since->toDateString() : '');

            return $this->rememberKpi($key, static function () use ($table, $sinceColumn, $since): array {
                $query = DB::table($table);

                if ($sinceColumn !== null && $since !== null) {
                    $query->where($sinceColumn, '>=', $since);
                }

                return $query
                    ->selectRaw('status, count(*) as c')
                    ->groupBy('status')
                    ->pluck('c', 'status')
                    ->map(static fn ($v): int => (int) $v)
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Medianoche de hoy en la zona de la plataforma (America/La_Paz).
     */
    protected function startOfToday(): Carbon
    {
        return now()->startOfDay();
    }
}
