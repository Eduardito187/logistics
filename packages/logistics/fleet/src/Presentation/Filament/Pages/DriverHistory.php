<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistory;
use Logistics\Fleet\Application\ReadModel\DriverHistoryView;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Historial operativo del chofer (desempeño cuantificable): elegido un chofer y un rango de fechas,
 * muestra los totales (días trabajados, camiones, paradas, entregadas, fallidas y por qué, reprogramadas)
 * y el desglose día por día (camión, ayudantes, cierres). Los NÚMEROS salen del lado de lectura CQRS
 * (QueryBus → GetDriverHistory → DriverHistoryView), nunca de un acceso directo al agregado.
 *
 * Todo texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class DriverHistory extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 25;

    protected string $view = 'logistics-fleet::filament.pages.driver-history';

    /** Chofer mirado (UUID v7) y rango de fechas en 'Y-m-d'. Enlazados a la acción de cabecera. */
    public ?string $driverId = null;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        // Por defecto: del primer día del mes corriente hasta hoy.
        if ($this->from === '') {
            $this->from = date('Y-m-01');
        }
        if ($this->to === '') {
            $this->to = date('Y-m-d');
        }
    }

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationLabel(): string
    {
        return __('Driver performance');
    }

    public function getTitle(): string
    {
        return __('Driver performance');
    }

    /**
     * Acción de cabecera: elegir chofer y rango de fechas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->chooseAction()];
    }

    /**
     * Flujo "Choose driver": selecciona el chofer (buscable, datos reales) y el rango, y refresca la vista.
     */
    public function chooseAction(): Action
    {
        return Action::make('choose')
            ->label(__('Choose driver & range'))
            ->icon('heroicon-o-user')
            ->modalHeading(__('Driver performance'))
            ->modalDescription(__('Pick a driver and a date range to see their operational history.'))
            ->modalSubmitActionLabel(__('Show history'))
            ->fillForm(fn (): array => [
                'driver_id' => $this->driverId,
                'from' => $this->from,
                'to' => $this->to,
            ])
            ->schema([
                Select::make('driver_id')
                    ->label(__('Driver'))
                    ->native(false)
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(static fn (string $search): array => DriverModel::query()
                        ->where(function (Builder $query) use ($search): void {
                            /** @var Builder<DriverModel> $query */
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('license_number', 'like', "%{$search}%");
                        })
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(static fn (DriverModel $driver): array => [$driver->id => $driver->name])
                        ->all())
                    ->getOptionLabelUsing(static function (string $value): ?string {
                        $driver = DriverModel::query()->find($value);

                        return $driver instanceof DriverModel ? $driver->name : null;
                    }),
                DatePicker::make('from')
                    ->label(__('From'))
                    ->native(false)
                    ->required()
                    ->default(date('Y-m-01')),
                DatePicker::make('to')
                    ->label(__('To'))
                    ->native(false)
                    ->required()
                    ->default(date('Y-m-d')),
            ])
            ->action(function (array $data): void {
                $this->driverId = is_string($data['driver_id'] ?? null) ? $data['driver_id'] : null;
                $this->from = is_string($data['from'] ?? null) && $data['from'] !== ''
                    ? substr($data['from'], 0, 10)
                    : date('Y-m-01');
                $this->to = is_string($data['to'] ?? null) && $data['to'] !== ''
                    ? substr($data['to'], 0, 10)
                    : date('Y-m-d');
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->driverId === null) {
            return [
                'history' => null,
                'reasonLabels' => $this->reasonLabels(),
            ];
        }

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var DriverHistoryView $view */
        $view = $queryBus->ask(new GetDriverHistory($this->driverId, $this->from, $this->to));

        return [
            'history' => $view->toArray(),
            'reasonLabels' => $this->reasonLabels(),
        ];
    }

    /**
     * Etiquetas legibles (inglés) de cada motivo de fallo, para los badges del desglose.
     *
     * @return array<string, string>
     */
    private function reasonLabels(): array
    {
        return [
            FailureReason::CustomerAbsent->value => __('Customer absent'),
            FailureReason::WrongAddress->value => __('Wrong address'),
            FailureReason::Rejected->value => __('Rejected'),
            FailureReason::Damaged->value => __('Damaged'),
            FailureReason::Other->value => __('Other'),
        ];
    }
}
