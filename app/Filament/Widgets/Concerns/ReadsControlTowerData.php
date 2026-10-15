<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Concerns;

use App\Enums\Role;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lectura directa (solo lectura) de las tablas para los widgets de la torre de control.
 *
 * Todos los conteos toleran tablas ausentes: si un contexto todavía no corrió sus migraciones
 * (o su tabla no existe en este entorno) se devuelve cero en vez de romper el tablero. Nunca se
 * escribe ni se instancian modelos de dominio: son consultas de agregación sobre el esquema.
 */
trait ReadsControlTowerData
{
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
            return DB::table($table)->count();
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
            return DB::table($table)->where($column, $value)->count();
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
            $query = DB::table($table);

            if ($sinceColumn !== null) {
                $query->where($sinceColumn, '>=', $this->startOfToday());
            }

            return $query
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(static fn ($v): int => (int) $v)
                ->all();
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
