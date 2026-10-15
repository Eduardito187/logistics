<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Resumen operativo de la plataforma: los números que importan de un vistazo.
 *
 * Lee conteos directos de las tablas (solo lectura); tolera tablas ausentes para no romper el panel.
 */
final class PlatformStatsWidget extends StatsOverviewWidget
{
    // KPIs globales: al fondo de la torre (la narrativa va de excepciones a totales).
    protected static ?int $sort = 9;

    /**
     * Solo lo ve quien tenga un rol con alcance operativo global (administrador o supervisor).
     *
     * Son KPIs de toda la plataforma: no tienen sentido para roles acotados (chofer, preparador,
     * tienda, transportista). La validación usa los roles de la cuenta autenticada (enum {@see Role}
     * sobre la tabla pivote `user_roles`, vía el trait HasRoles de {@see User}).
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor);
    }

    /**
     * Ocupa todo el ancho del tablero (sin huecos a los lados).
     */
    protected int | string | array $columnSpan = 'full';

    /**
     * Cuatro tarjetas por fila en pantallas anchas: las 8 métricas quedan en dos
     * filas parejas que llenan el ancho; Filament colapsa a 1-2 columnas en móvil.
     */
    protected int | array | null $columns = 4;

    protected function getStats(): array
    {
        $deliveries = $this->countByStatus('execution_deliveries');
        $promises = $this->countByStatus('promises');

        return [
            Stat::make(__('Orders received'), (string) $this->count('shipments'))
                ->description(__('Total registered orders'))
                ->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color('primary'),

            Stat::make(__('Out for delivery'), (string) ($deliveries['IN_TRANSIT'] ?? 0))
                ->description(__('On route right now'))
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning'),

            Stat::make(__('Delivered'), (string) ($deliveries['DELIVERED'] ?? 0))
                ->description(__('Completed deliveries'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make(__('Failed deliveries'), (string) ($deliveries['FAILED'] ?? 0))
                ->description(__('Need rescheduling'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make(__("Today's plans"), (string) $this->count('planning_plans'))
                ->description(__('Dispatch plans'))
                ->descriptionIcon('heroicon-m-map')
                ->color('gray'),

            Stat::make(__('Reserved promises'), (string) (($promises['RESERVED'] ?? 0) + ($promises['CONFIRMED'] ?? 0)))
                ->description(__('Committed delivery windows'))
                ->descriptionIcon('heroicon-m-clock')
                ->color('primary'),

            Stat::make(__('Active vehicles'), (string) $this->count('fleet_vehicles'))
                ->description(__('Fleet in service'))
                ->descriptionIcon('heroicon-m-truck')
                ->color('gray'),

            Stat::make(__('Outbox dead-letter'), (string) $this->whereCount('outbox_messages', 'status', 'dead_lettered'))
                ->description(__('Messages that need attention'))
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color('danger'),
        ];
    }

    private function count(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function whereCount(string $table, string $column, string $value): int
    {
        try {
            return DB::table($table)->where($column, $value)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(string $table): array
    {
        try {
            return DB::table($table)
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(fn ($v) => (int) $v)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
