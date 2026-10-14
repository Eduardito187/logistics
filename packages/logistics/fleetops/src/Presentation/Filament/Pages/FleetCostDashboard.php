<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenance;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Tablero de costos de flota del admin.
 *
 * Muestra dos cosas: (1) los planes de mantenimiento preventivo por vencer o vencidos (ListDueMaintenance,
 * con distintivo overdue/due-soon) y (2) el resumen de costos de un vehículo en un rango de fechas
 * (GetVehicleCostSummary): combustible + mantenimiento + gastos = costo total (TCO), y el costo por km.
 *
 * Nota de arquitectura: los NÚMEROS salen del lado de lectura CQRS (QueryBus), nunca de un acceso directo
 * al agregado. La placa del vehículo se resuelve desde `fleet_vehicles` (cross-context, solo lectura). Es
 * una página de SOLO lectura: no muta nada. Texto visible en inglés (regla 5); comentarios en español (6).
 */
final class FleetCostDashboard extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 90;

    protected string $view = 'fleetops.filament.pages.fleet-cost-dashboard';

    /**
     * Último resumen de costos calculado, en forma primitiva lista para la vista (o null si aún no se
     * pidió ninguno). Se cachea la forma primitiva, nunca el objeto de dominio (regla 7 de CLAUDE.md).
     *
     * @var array<string, string>|null
     */
    public ?array $summary = null;

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fleet cost dashboard');
    }

    public function getTitle(): string
    {
        return __('Fleet cost dashboard');
    }

    /**
     * Acción de cabecera: calcular el resumen de costos de un vehículo en un rango de fechas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->costSummaryAction(),
        ];
    }

    /**
     * Flujo "Cost summary": pide GetVehicleCostSummary al QueryBus para el vehículo y rango elegidos, y
     * guarda el resultado (formateado en bolivianos) para mostrarlo bajo el tablero.
     */
    public function costSummaryAction(): Action
    {
        return Action::make('cost_summary')
            ->label(__('Cost summary'))
            ->icon('heroicon-o-calculator')
            ->modalHeading(__('Vehicle cost summary'))
            ->modalSubmitActionLabel(__('Calculate'))
            ->schema([
                Select::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(static fn (string $search): array => VehiclePlates::search($search))
                    ->getOptionLabelUsing(static fn (string $value): ?string => VehiclePlates::label($value)),
                DatePicker::make('from')
                    ->label(__('From'))
                    ->native(false)
                    ->default(date('Y-m-01'))
                    ->required(),
                DatePicker::make('to')
                    ->label(__('To'))
                    ->native(false)
                    ->default(date('Y-m-d'))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $vehicleId = (string) $data['vehicle_id'];
                $from = substr((string) $data['from'], 0, 10);
                $to = substr((string) $data['to'], 0, 10);

                /** @var QueryBus $queryBus */
                $queryBus = app(QueryBus::class);
                /** @var VehicleCostSummaryView $view */
                $view = $queryBus->ask(new GetVehicleCostSummary($vehicleId, $from, $to));

                $this->summary = [
                    'vehicle' => VehiclePlates::label($view->vehicleId) ?? $view->vehicleId,
                    'from' => $view->from,
                    'to' => $view->to,
                    'fuel' => number_format($view->fuelCents / 100, 2),
                    'maintenance' => number_format($view->maintenanceCents / 100, 2),
                    'expenses' => number_format($view->expensesCents / 100, 2),
                    'total' => number_format($view->totalCents / 100, 2),
                    'currency' => $view->currency,
                    'kilometers' => number_format($view->kilometers),
                    'cost_per_km' => $view->costPerKmCents === null
                        ? '—'
                        : number_format($view->costPerKmCents / 100, 2),
                ];

                Notification::make()
                    ->title(__('Cost summary'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Datos para la vista: la lista de mantenimientos por vencer (con placa y distintivo) y el resumen.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<DueMaintenanceView> $due */
        $due = $queryBus->ask(new ListDueMaintenance);

        $rows = [];
        foreach ($due as $item) {
            $rows[] = [
                'vehicle' => VehiclePlates::label($item->vehicleId) ?? $item->vehicleId,
                'service_kind' => __(ServiceKind::from($item->serviceKind)->label()),
                'next_due_km' => $item->nextDueKm,
                'next_due_at' => $item->nextDueAt,
                'current_odometer_km' => $item->currentOdometerKm,
                'overdue' => $item->overdue,
            ];
        }

        return [
            'due' => $rows,
            'summary' => $this->summary,
        ];
    }
}
