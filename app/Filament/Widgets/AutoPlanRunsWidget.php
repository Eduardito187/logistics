<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Torre de control — últimas corridas del auto-planificador diario. Muestra el ciclo
 * (queued/processing/completed/failed) y el resumen (camiones, paradas, pedidos sin asignar) para
 * ver de un vistazo si el ruteo del día corrió y con qué resultado.
 *
 * Lee `planning_auto_plan_runs` por consulta directa (solo lectura), sin modelo de dominio, y tolera
 * la tabla ausente (devuelve una tabla vacía). Las fechas se formatean en la zona de la plataforma.
 */
final class AutoPlanRunsWidget extends TableWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 8;

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Auto-plan runs'))
            ->description(__('Latest daily auto-planner executions.'))
            ->records(fn (): array => $this->recentRuns())
            ->paginated(false)
            ->emptyStateHeading(__('No auto-plan runs yet'))
            ->emptyStateIcon('heroicon-o-map')
            ->columns([
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __(ucfirst(strtolower($state))))
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('plan_date')
                    ->label(__('Plan date')),
                TextColumn::make('strategy')
                    ->label(__('Strategy'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('trucks_planned')
                    ->label(__('Trucks'))
                    ->alignEnd(),
                TextColumn::make('stops_planned')
                    ->label(__('Stops'))
                    ->alignEnd(),
                TextColumn::make('unassigned_count')
                    ->label(__('Unassigned'))
                    ->alignEnd()
                    ->color(fn (string $state): string => ((int) $state) > 0 ? 'danger' : 'gray'),
                TextColumn::make('requested_at')
                    ->label(__('Requested at')),
            ]);
    }

    /**
     * Las últimas corridas, ya formateadas como filas de arreglo (sin modelo de dominio). Vacío si la
     * tabla no existe en este entorno.
     *
     * @return array<int, array<string, string>>
     */
    private function recentRuns(): array
    {
        try {
            $rows = DB::table('planning_auto_plan_runs')
                ->orderByDesc('requested_at')
                ->limit(8)
                ->get();

            return $rows->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'plan_date' => (string) $row->plan_date,
                'strategy' => (string) $row->strategy,
                'trucks_planned' => (string) $row->trucks_planned,
                'stops_planned' => (string) $row->stops_planned,
                'unassigned_count' => (string) $row->unassigned_count,
                'requested_at' => Carbon::parse((string) $row->requested_at)->format('Y-m-d H:i'),
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
