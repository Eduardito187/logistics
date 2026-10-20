<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Pages;

use BackedEnum;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentType;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Application\Query\GetCarrierCostBreakdown\GetCarrierCostBreakdown;
use Logistics\FleetOps\Application\Query\GetCorridorCostBreakdown\GetCorridorCostBreakdown;
use Logistics\FleetOps\Application\Query\GetCorridorProfitability\GetCorridorProfitability;
use Logistics\FleetOps\Application\Query\GetCurrentFuelPrice\GetCurrentFuelPrice;
use Logistics\FleetOps\Application\Query\GetDailyFuelSpend\GetDailyFuelSpend;
use Logistics\FleetOps\Application\Query\GetVehicleCostBreakdown\GetVehicleCostBreakdown;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\Query\ListDueMaintenance\ListDueMaintenance;
use Logistics\FleetOps\Application\ReadModel\CarrierCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CorridorCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityView;
use Logistics\FleetOps\Application\ReadModel\DailyFuelSpendView;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Application\ReadModel\FuelPriceView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Cockpit de SALUD de flota del admin (operaciones-primero).
 *
 * La página se ordena por lo que requiere acción AHORA y deja los KPIs de costo al final:
 *   1. Mantenimientos vencidos / por vencer (ListDueMaintenance) → acción inline: agendar la orden
 *      despachando el comando ScheduleMaintenance al bus CQRS (mismo patrón que MaintenanceOrderResource),
 *      con enlace de drill-down al recurso de órdenes.
 *   2. Licencias y documentos por vencer o vencidos (ListExpiringDocuments, contexto fleet, SOLO lectura
 *      cross-context por el QueryBus; no se duplica la lógica de dominio) → enlace al recurso del dueño.
 *   3. Vehículos sin lectura de odómetro reciente → acción inline: registrar lectura (RecordOdometer).
 *   4. KPI de combustible del día (GetDailyFuelSpend) con el precio de diésel vigente (GetCurrentFuelPrice).
 *   5. Resumen de costos (TCO) y costo por km de un vehículo en un rango (GetVehicleCostSummary): se mantiene,
 *      pero movido debajo de las excepciones.
 *
 * Nota de arquitectura: los números salen del lado de lectura CQRS (QueryBus) y las mutaciones del lado de
 * escritura (CommandBus); nunca se escribe el agregado desde aquí. La única lectura directa a tabla es la de
 * odómetros atrasados: es una proyección de SOLO presentación, tolerante a tablas ausentes (patrón
 * ReadsControlTowerData), porque no hay un ReadModel de flota para "sin lectura reciente". La placa del
 * vehículo se resuelve desde `fleet_vehicles` (cross-context, solo lectura). Texto visible en inglés
 * (regla 5); comentarios en español (regla 6).
 */
final class FleetCostDashboard extends Page
{
    use OfficeOnly;

    /** Días sin lectura de odómetro a partir de los cuales un vehículo se considera "atrasado". */
    private const ODOMETER_STALE_DAYS = 14;

    /** Vida del caché de las proyecciones de odómetro (segundos); el atraso se mide en días, 5 min sobra. */
    private const STALE_ODOMETER_TTL_SECONDS = 300;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-heart';

    protected string $view = 'fleetops.filament.pages.fleet-cost-dashboard';

    /**
     * Último resumen de costos calculado, en forma primitiva lista para la vista (o null si aún no se
     * pidió ninguno). Se cachea la forma primitiva, nunca el objeto de dominio (regla 7 de CLAUDE.md).
     *
     * @var array<string, string>|null
     */
    public ?array $summary = null;

    /**
     * Último desglose de costos (por vehículo / corredor / tercero) y rentabilidad por corredor calculado
     * para un rango, en forma primitiva lista para la vista (o null si aún no se pidió). Se cachea la
     * forma primitiva, nunca objetos de dominio (regla 7 de CLAUDE.md).
     *
     * @var array<string, mixed>|null
     */
    public ?array $breakdown = null;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 6;
    }

    public static function getNavigationLabel(): string
    {
        return __('Fleet health');
    }

    public function getTitle(): string
    {
        return __('Fleet health');
    }

    /**
     * Acciones de cabecera del cockpit: agendar mantenimiento, registrar odómetro y el resumen de costos.
     * Registrarlas aquí monta sus modales, que las acciones por fila (botones de la vista) reutilizan vía
     * `mountAction(...)` con los argumentos del vehículo precargados.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->scheduleMaintenanceAction(),
            $this->recordOdometerAction(),
            $this->costSummaryAction(),
            $this->costBreakdownAction(),
        ];
    }

    /**
     * Flujo "Cost & profitability": pide los desgloses de costo por vehículo/corredor/tercero y la
     * rentabilidad por corredor (GetVehicleCostBreakdown / GetCorridorCostBreakdown /
     * GetCarrierCostBreakdown / GetCorridorProfitability) al QueryBus para el rango elegido, y guarda el
     * resultado formateado en bolivianos para mostrarlo bajo el resumen por vehículo. La rentabilidad
     * muestra solo costo mientras no haya ingreso configurado (placeholder listo para enchufar ingreso).
     */
    public function costBreakdownAction(): Action
    {
        return Action::make('cost_breakdown')
            ->label(__('Cost & profitability'))
            ->icon('heroicon-o-chart-bar')
            ->color('gray')
            ->modalHeading(__('Cost & profitability by range'))
            ->modalSubmitActionLabel(__('Calculate'))
            ->schema([
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
                $from = substr((string) $data['from'], 0, 10);
                $to = substr((string) $data['to'], 0, 10);

                /** @var QueryBus $queryBus */
                $queryBus = app(QueryBus::class);

                /** @var VehicleCostBreakdownView $vehicles */
                $vehicles = $queryBus->ask(new GetVehicleCostBreakdown($from, $to));
                /** @var CorridorCostBreakdownView $corridors */
                $corridors = $queryBus->ask(new GetCorridorCostBreakdown($from, $to));
                /** @var CarrierCostBreakdownView $carriers */
                $carriers = $queryBus->ask(new GetCarrierCostBreakdown($from, $to));
                /** @var CorridorProfitabilityView $profitability */
                $profitability = $queryBus->ask(new GetCorridorProfitability($from, $to));

                $this->breakdown = [
                    'from' => $from,
                    'to' => $to,
                    'vehicles' => $this->formatVehicleLines($vehicles),
                    'corridors' => $this->formatCorridorLines($corridors),
                    'carriers' => $this->formatCarrierLines($carriers),
                    'profitability' => $this->formatProfitability($profitability),
                ];

                Notification::make()
                    ->title(__('Cost & profitability'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Filas del desglose por vehículo, con la placa resuelta y los importes en bolivianos.
     *
     * @return list<array{vehicle: string, fuel: string, maintenance: string, expenses: string, total: string, kilometers: string, cost_per_km: string, currency: string}>
     */
    private function formatVehicleLines(VehicleCostBreakdownView $view): array
    {
        $rows = [];
        foreach ($view->lines as $line) {
            $rows[] = [
                'vehicle' => VehiclePlates::label($line->vehicleId) ?? '—',
                'fuel' => number_format($line->fuelCents / 100, 2),
                'maintenance' => number_format($line->maintenanceCents / 100, 2),
                'expenses' => number_format($line->expensesCents / 100, 2),
                'total' => number_format($line->totalCents / 100, 2),
                'kilometers' => number_format($line->kilometers),
                'cost_per_km' => $line->costPerKmCents === null ? '—' : number_format($line->costPerKmCents / 100, 2),
                'currency' => $line->currency,
            ];
        }

        return $rows;
    }

    /**
     * Filas del desglose por corredor, con los importes en bolivianos.
     *
     * @return list<array{corridor: string, departures: int, kilometers: string, deliveries: int, cost: string, cost_per_km: string, cost_per_delivery: string, currency: string}>
     */
    private function formatCorridorLines(CorridorCostBreakdownView $view): array
    {
        $rows = [];
        foreach ($view->lines as $line) {
            $rows[] = [
                'corridor' => $line->corridor,
                'departures' => $line->departures,
                'kilometers' => number_format($line->kilometers),
                'deliveries' => $line->deliveries,
                'cost' => number_format($line->costCents / 100, 2),
                'cost_per_km' => $line->costPerKmCents === null ? '—' : number_format($line->costPerKmCents / 100, 2),
                'cost_per_delivery' => $line->costPerDeliveryCents === null ? '—' : number_format($line->costPerDeliveryCents / 100, 2),
                'currency' => $line->currency,
            ];
        }

        return $rows;
    }

    /**
     * Filas del desglose por tercero (transportista), con los importes en bolivianos.
     *
     * @return list<array{carrier: string, settlements: int, corridors: int, cost: string, currency: string}>
     */
    private function formatCarrierLines(CarrierCostBreakdownView $view): array
    {
        $rows = [];
        foreach ($view->lines as $line) {
            $rows[] = [
                'carrier' => $line->carrierName ?? $line->carrierId,
                'settlements' => $line->settlements,
                'corridors' => $line->corridors,
                'cost' => number_format($line->costCents / 100, 2),
                'currency' => $line->currency,
            ];
        }

        return $rows;
    }

    /**
     * Rentabilidad por corredor formateada; el margen cae a '—' mientras no haya ingreso configurado.
     *
     * @return array{revenue_available: bool, currency: string, total_cost: string, total_revenue: string, total_margin: string, lines: list<array{corridor: string, deliveries: int, cost: string, revenue: string, margin: string, margin_ratio: string}>}
     */
    private function formatProfitability(CorridorProfitabilityView $view): array
    {
        $lines = [];
        foreach ($view->lines as $line) {
            $lines[] = [
                'corridor' => $line->corridor,
                'deliveries' => $line->deliveries,
                'cost' => number_format($line->costCents / 100, 2),
                'revenue' => $line->revenueCents === null ? '—' : number_format($line->revenueCents / 100, 2),
                'margin' => $line->marginCents === null ? '—' : number_format($line->marginCents / 100, 2),
                'margin_ratio' => $line->marginRatio === null ? '—' : number_format($line->marginRatio * 100, 1).'%',
            ];
        }

        return [
            'revenue_available' => $view->revenueAvailable,
            'currency' => $view->currency,
            'total_cost' => number_format($view->totalCostCents / 100, 2),
            'total_revenue' => $view->totalRevenueCents === null ? '—' : number_format($view->totalRevenueCents / 100, 2),
            'total_margin' => $view->totalMarginCents === null ? '—' : number_format($view->totalMarginCents / 100, 2),
            'lines' => $lines,
        ];
    }

    /**
     * Acción transaccional "Schedule order": agenda una orden de mantenimiento despachando el comando
     * ScheduleMaintenance al bus CQRS. Precarga el vehículo cuando se monta desde una fila de excepción.
     */
    public function scheduleMaintenanceAction(): Action
    {
        return Action::make('scheduleMaintenance')
            ->label(__('Schedule order'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('warning')
            ->modalHeading(__('Schedule maintenance order'))
            ->modalSubmitActionLabel(__('Schedule'))
            ->fillForm(static fn (array $arguments): array => [
                'vehicle_id' => is_string($arguments['vehicle_id'] ?? null) ? $arguments['vehicle_id'] : null,
            ])
            ->schema([
                Select::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(static fn (string $search): array => VehiclePlates::search($search))
                    ->getOptionLabelUsing(static fn (string $value): ?string => VehiclePlates::label($value)),
                Select::make('type')
                    ->label(__('Type'))
                    ->options(self::maintenanceTypeOptions())
                    ->native(false)
                    ->default(MaintenanceType::Preventive->value)
                    ->required(),
                TextInput::make('odometer_km')
                    ->label(__('Odometer'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('workshop')
                    ->label(__('Workshop'))
                    ->maxLength(255),
                DateTimePicker::make('scheduled_at')
                    ->label(__('Scheduled at'))
                    ->native(false)
                    ->seconds(false)
                    ->default(now())
                    ->required(),
                TextInput::make('note')
                    ->label(__('Note'))
                    ->maxLength(255),
            ])
            ->action(static function (array $data): void {
                app(CommandBus::class)->dispatch(new ScheduleMaintenance(
                    vehicleId: (string) $data['vehicle_id'],
                    type: (string) $data['type'],
                    odometerKm: is_numeric($data['odometer_km'] ?? null) ? (int) $data['odometer_km'] : null,
                    workshop: self::nullableString($data['workshop'] ?? null),
                    scheduledAt: (string) $data['scheduled_at'],
                    note: self::nullableString($data['note'] ?? null),
                ));

                Notification::make()
                    ->title(__('Maintenance scheduled'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción transaccional "Record odometer": registra una lectura de odómetro despachando RecordOdometer.
     * Precarga el vehículo cuando se monta desde una fila de odómetros atrasados.
     */
    public function recordOdometerAction(): Action
    {
        return Action::make('recordOdometer')
            ->label(__('Record odometer'))
            ->icon('heroicon-o-arrow-trending-up')
            ->modalHeading(__('Record odometer reading'))
            ->modalSubmitActionLabel(__('Record reading'))
            ->fillForm(static fn (array $arguments): array => [
                'vehicle_id' => is_string($arguments['vehicle_id'] ?? null) ? $arguments['vehicle_id'] : null,
            ])
            ->schema([
                Select::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(static fn (string $search): array => VehiclePlates::search($search))
                    ->getOptionLabelUsing(static fn (string $value): ?string => VehiclePlates::label($value)),
                TextInput::make('km')
                    ->label(__('Odometer'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                DateTimePicker::make('read_at')
                    ->label(__('Read at'))
                    ->native(false)
                    ->seconds(false)
                    ->default(now())
                    ->required(),
                TextInput::make('note')
                    ->label(__('Note'))
                    ->maxLength(255),
            ])
            ->action(static function (array $data): void {
                app(CommandBus::class)->dispatch(new RecordOdometer(
                    vehicleId: (string) $data['vehicle_id'],
                    km: (int) $data['km'],
                    source: OdometerSource::Manual->value,
                    readAt: (string) $data['read_at'],
                    note: self::nullableString($data['note'] ?? null),
                ));

                Notification::make()
                    ->title(__('Odometer recorded'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Cost summary": pide GetVehicleCostSummary al QueryBus para el vehículo y rango elegidos, y
     * guarda el resultado (formateado en bolivianos) para mostrarlo bajo las excepciones.
     */
    public function costSummaryAction(): Action
    {
        return Action::make('cost_summary')
            ->label(__('Cost summary'))
            ->icon('heroicon-o-calculator')
            ->color('gray')
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
                    'vehicle' => VehiclePlates::label($view->vehicleId) ?? '—',
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
     * Datos para la vista: excepciones (mantenimientos, documentos, odómetros atrasados), el KPI de
     * combustible y el resumen de costos ya calculado.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'due' => $this->dueMaintenanceRows(),
            'documents' => $this->expiringDocumentRows(),
            'stale' => $this->staleOdometerRows(),
            'fuel' => $this->fuelKpi(),
            'ordersUrl' => $this->resourceIndexUrl(MaintenanceOrderResource::class),
            'readingsUrl' => $this->resourceIndexUrl(OdometerReadingResource::class),
            'summary' => $this->summary,
            'breakdown' => $this->breakdown,
        ];
    }

    /**
     * Filas de mantenimiento vencido / por vencer. `vehicle_id` viaja solo como argumento de la acción
     * inline (nunca se pinta); lo visible es la placa.
     *
     * @return list<array{vehicle: string, vehicle_id: string, service_kind: string, next_due_km: int|null, next_due_at: string|null, current_odometer_km: int|null, overdue: bool}>
     */
    private function dueMaintenanceRows(): array
    {
        try {
            /** @var list<DueMaintenanceView> $due */
            $due = app(QueryBus::class)->ask(new ListDueMaintenance);
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach ($due as $item) {
            $rows[] = [
                'vehicle' => VehiclePlates::label($item->vehicleId) ?? '—',
                'vehicle_id' => $item->vehicleId,
                'service_kind' => __(ServiceKind::from($item->serviceKind)->label()),
                'next_due_km' => $item->nextDueKm,
                'next_due_at' => $item->nextDueAt,
                'current_odometer_km' => $item->currentOdometerKm,
                'overdue' => $item->overdue,
            ];
        }

        return $rows;
    }

    /**
     * Filas de licencias y documentos por vencer o vencidos, leídas del contexto fleet por el QueryBus
     * (ventana de 30 días) y ordenadas de lo más urgente a lo menos. Enlazan al recurso del dueño.
     *
     * @return list<array{threshold: string, threshold_label: string, owner_type_label: string, owner_label: string, document_type_label: string, expires_on: string, url: string|null}>
     */
    private function expiringDocumentRows(): array
    {
        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = app(QueryBus::class)->ask(new ListExpiringDocuments(30));
        } catch (Throwable) {
            return [];
        }

        $ref = new DateTimeImmutable('today');

        $rows = [];
        foreach ($views as $view) {
            $threshold = $view->thresholdAt($ref);
            $rows[] = [
                'threshold' => $threshold,
                'threshold_label' => $this->thresholdLabel($threshold),
                'owner_type_label' => $this->ownerTypeLabel($view->ownerType),
                'owner_label' => $view->ownerLabel !== '' ? $view->ownerLabel : '—',
                'document_type_label' => $this->documentTypeLabel($view->documentType),
                'expires_on' => $view->expiresOn,
                'url' => $this->ownerUrl($view->ownerType, $view->ownerId),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $order = [
                ExpiringDocumentView::THRESHOLD_EXPIRED => 0,
                ExpiringDocumentView::THRESHOLD_DUE_SOON => 1,
                ExpiringDocumentView::THRESHOLD_UPCOMING => 2,
            ];

            return [$order[$a['threshold']] ?? 3, $a['expires_on']]
                <=> [$order[$b['threshold']] ?? 3, $b['expires_on']];
        });

        return $rows;
    }

    /**
     * Vehículos sin lectura de odómetro reciente (o nunca). Proyección de SOLO lectura, tolerante a
     * tablas ausentes (patrón ReadsControlTowerData): si el esquema no existe en este entorno devuelve
     * una lista vacía en vez de romper el cockpit. Se excluyen los vehículos dados de baja ('inactive').
     *
     * @return list<array{vehicle: string, vehicle_id: string, last_read_at: string|null, days_since: int|null}>
     */
    private function staleOdometerRows(): array
    {
        try {
            $threshold = now()->subDays(self::ODOMETER_STALE_DAYS);

            // Ambas proyecciones son de SOLO presentación y se cachean en forma primitiva (regla 7):
            // el GROUP BY sobre `fleetops_odometer_readings` (append-only, crece sin tope) y el catálogo
            // de vehículos. Con TTL de minutos el cockpit no dispara el agregado ni relee toda la flota en
            // cada render (auditoría A-1 / FleetCostDashboard).

            // Última lectura por vehículo (id → fecha ISO), para medir la antigüedad.
            /** @var array<string, string> $lastReads */
            $lastReads = Cache::remember(
                'fleet-cost:odometer-last-reads',
                self::STALE_ODOMETER_TTL_SECONDS,
                static fn (): array => DB::table('fleetops_odometer_readings')
                    ->selectRaw('vehicle_id, MAX(read_at) as last_read_at')
                    ->groupBy('vehicle_id')
                    ->pluck('last_read_at', 'vehicle_id')
                    ->map(static fn ($value): string => (string) $value)
                    ->all(),
            );

            // Catálogo de vehículos activos (id → placa), acotado y cacheado para no releer toda la flota.
            /** @var array<string, string> $vehicles */
            $vehicles = Cache::remember(
                'fleet-cost:active-vehicles',
                self::STALE_ODOMETER_TTL_SECONDS,
                static fn (): array => DB::table('fleet_vehicles')
                    ->where('status', '!=', 'inactive')
                    ->orderBy('plate')
                    ->pluck('plate', 'id')
                    ->map(static fn ($plate): string => (string) $plate)
                    ->all(),
            );

            $rows = [];
            foreach ($vehicles as $vehicleId => $plate) {
                $vehicleId = (string) $vehicleId;
                $lastRaw = $lastReads[$vehicleId] ?? null;

                // Vehículo sin ninguna lectura: siempre es una excepción.
                if ($lastRaw === null) {
                    $rows[] = [
                        'vehicle' => $plate,
                        'vehicle_id' => $vehicleId,
                        'last_read_at' => null,
                        'days_since' => null,
                    ];

                    continue;
                }

                $lastAt = new DateTimeImmutable($lastRaw);

                // Solo las que superan el umbral de antigüedad entran al cockpit.
                if ($lastAt->getTimestamp() >= $threshold->getTimestamp()) {
                    continue;
                }

                $daysSince = (int) floor((time() - $lastAt->getTimestamp()) / 86400);

                $rows[] = [
                    'vehicle' => $plate,
                    'vehicle_id' => $vehicleId,
                    'last_read_at' => $lastAt->format('Y-m-d'),
                    'days_since' => $daysSince,
                ];
            }

            // Lo más atrasado primero; los "nunca" (null) al tope.
            usort($rows, static fn (array $a, array $b): int => ($b['days_since'] ?? PHP_INT_MAX) <=> ($a['days_since'] ?? PHP_INT_MAX));

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * KPI de combustible del día: gasto de hoy y precio de diésel vigente, ambos tolerantes a fallos.
     *
     * @return array{date: string, spend: string, currency: string, price: string, price_currency: string}
     */
    private function fuelKpi(): array
    {
        $today = date('Y-m-d');

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);

        $spend = '—';
        $currency = 'BOB';
        try {
            /** @var DailyFuelSpendView $view */
            $view = $queryBus->ask(new GetDailyFuelSpend($today));
            $spend = number_format($view->totalCostCents / 100, 2);
            $currency = $view->currency;
        } catch (Throwable) {
            // Tabla ausente o lectura fallida: el KPI cae a '—' sin romper el cockpit.
        }

        $price = '—';
        $priceCurrency = $currency;
        try {
            $priceView = $queryBus->ask(new GetCurrentFuelPrice(FuelType::Diesel->value));
            if ($priceView instanceof FuelPriceView) {
                $price = number_format($priceView->priceCents / 100, 2);
                $priceCurrency = $priceView->currency;
            }
        } catch (Throwable) {
            // Sin precio vigente: se muestra '—'.
        }

        return [
            'date' => $today,
            'spend' => $spend,
            'currency' => $currency,
            'price' => $price,
            'price_currency' => $priceCurrency,
        ];
    }

    /**
     * Opciones valor→etiqueta de los tipos de mantenimiento (texto en inglés desde el enum).
     *
     * @return array<string, string>
     */
    private static function maintenanceTypeOptions(): array
    {
        $options = [];
        foreach (MaintenanceType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Normaliza un campo de texto opcional del formulario a string no vacío o null.
     */
    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function thresholdLabel(string $threshold): string
    {
        return match ($threshold) {
            ExpiringDocumentView::THRESHOLD_EXPIRED => (string) __('Expired'),
            ExpiringDocumentView::THRESHOLD_DUE_SOON => (string) __('Due within 7 days'),
            default => (string) __('Due within 30 days'),
        };
    }

    private function ownerTypeLabel(string $ownerType): string
    {
        return match ($ownerType) {
            'driver' => (string) __('Driver'),
            'helper' => (string) __('Helper'),
            'vehicle' => (string) __('Vehicle'),
            default => $ownerType,
        };
    }

    /**
     * Etiqueta legible del tipo de documento: usa el enum conocido de flota y cae al valor crudo si es
     * un tipo libre no previsto.
     */
    private function documentTypeLabel(string $documentType): string
    {
        $known = FleetDocumentType::tryFrom($documentType);

        return $known !== null ? (string) __($known->label()) : $documentType;
    }

    /**
     * URL al recurso del dueño del documento; null si el tipo no tiene recurso o la ruta no resuelve.
     */
    private function ownerUrl(string $ownerType, string $ownerId): ?string
    {
        $resource = match ($ownerType) {
            'driver' => DriverResource::class,
            'helper' => HelperResource::class,
            'vehicle' => VehicleResource::class,
            default => null,
        };

        if ($resource === null) {
            return null;
        }

        try {
            return $resource::getUrl('view', ['record' => $ownerId]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * URL del índice de un recurso para los enlaces de drill-down; null si la ruta no resuelve.
     *
     * @param class-string<\Filament\Resources\Resource> $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            return $resource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
