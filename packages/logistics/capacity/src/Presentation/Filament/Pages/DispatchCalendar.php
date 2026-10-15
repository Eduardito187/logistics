<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowCommand;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendar;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendarHandler;
use Logistics\Capacity\Application\Command\UnblockDispatchDay\UnblockDispatchDayCommand;
use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\Query\GetActiveHorizon\GetActiveHorizonQuery;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\ActiveHorizonView;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Capacity\Presentation\Filament\Support\MunicipalityOptions;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Calendario de despacho del admin, pensado en grande: eje por MUNICIPIO y vista de los SIETE días de la
 * semana (lunes a domingo). Para el municipio y la semana elegidos muestra, por día y por ventana,
 * total/usado/disponible, marca visualmente los días BLOQUEADOS (feriado u otro motivo) y los días fuera
 * del horizonte activo, y resume hasta cuándo hay cupos activos para ese municipio ("slots active until").
 *
 * Operaciones principales de la cabecera:
 * - "Activate slots": activa cupos por RANGO de fechas (hasta noviembre, por ejemplo), por día de la
 *   semana y por ventana (franja + unidad + cantidad), para uno o todos los municipios. Despacha
 *   ActivateCapacityWindowCommand al CommandBus; la generación de buckets corre ASÍNCRONA (job troceado).
 * - "Block a day" / acción rápida por día: bloquea o desbloquea un día (BlockDispatchDay / UnblockDispatchDay).
 * - "Edit rules" / "Generate calendar": control fino de la plantilla de reglas y su materialización.
 *
 * Nota de arquitectura: los NÚMEROS y la disponibilidad salen del lado de lectura CQRS (QueryBus →
 * ListBucketsByDateQuery / CheckDispatchDayQuery / GetActiveHorizonQuery), nunca de un acceso directo al
 * agregado. Las mutaciones despachan Commands al CommandBus. Los municipios se leen por el catálogo de
 * coverage (ListMunicipalities, vía MunicipalityOptions). Todo texto visible en inglés (regla 5);
 * comentarios en español (regla 6).
 */
final class DispatchCalendar extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 20;

    protected string $view = 'logistics-capacity::filament.pages.dispatch-calendar';

    /** Lunes de la semana que se está mirando, en 'Y-m-d'. Lo mueven los botones anterior/siguiente. */
    public string $weekStart = '';

    /**
     * Estado del formulario de filtros (municipio elegido). El select escribe en filters.municipality_id;
     * null/vacío = todos los municipios.
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    public function mount(): void
    {
        // Por defecto, la semana que contiene hoy (su lunes).
        if ($this->weekStart === '') {
            $this->weekStart = $this->mondayOf(date('Y-m-d'));
        }

        // Precarga el estado del formulario de filtros (sin municipio = todos).
        $this->getFiltersForm()->fill($this->filters);
    }

    /**
     * Cachea el formulario de filtros en cada request (patrón de los filtros de dashboard de Filament),
     * para que la vista lo renderice con el estado vivo de $this->filters.
     */
    public function booted(): void
    {
        $this->cacheSchema('filtersForm', $this->getFiltersForm());
    }

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dispatch calendar');
    }

    public function getTitle(): string
    {
        return __('Dispatch calendar');
    }

    /**
     * Formulario de filtros del calendario: el selector de MUNICIPIO, buscable y native(false), poblado
     * con datos reales del catálogo de coverage. Es `live()`, así cambiar de municipio recalcula la vista.
     */
    public function getFiltersForm(): Schema
    {
        if ((!$this->isCachingSchemas) && $this->hasCachedSchema('filtersForm')) {
            $cached = $this->getSchema('filtersForm');
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->makeSchema()
            ->statePath('filters')
            ->live()
            ->components([
                Select::make('municipality_id')
                    ->label(__('Municipality'))
                    ->placeholder(__('All municipalities'))
                    ->helperText(__('Pick a municipality to manage its weekly slots. Empty shows every municipality.'))
                    ->native(false)
                    ->searchable()
                    ->options(static fn (): array => MunicipalityOptions::all()),
            ]);
    }

    /**
     * Acciones de cabecera: activar cupos por rango, bloquear un día, y el control fino de reglas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->activateSlotsAction(),
            $this->blockDayAction(),
            $this->editRulesAction(),
            $this->generateAction(),
        ];
    }

    /**
     * Avanza o retrocede la semana mirada (botones del encabezado del calendario).
     */
    public function shiftWeek(int $weeks): void
    {
        $timestamp = strtotime($this->weekStart.' '.($weeks * 7).' day');
        // strtotime devuelve false si la fecha actual no es parseable: en ese caso no se mueve la semana.
        if ($timestamp === false) {
            return;
        }

        $this->weekStart = $this->mondayOf(date('Y-m-d', $timestamp));
    }

    /**
     * Vuelve a la semana que contiene hoy.
     */
    public function goToThisWeek(): void
    {
        $this->weekStart = $this->mondayOf(date('Y-m-d'));
    }

    /**
     * Acción rápida desde el calendario: desbloquea el día indicado para el municipio del filtro (o el
     * bloqueo global si no hay municipio elegido). Despacha UnblockDispatchDayCommand al CommandBus.
     */
    public function unblockDay(string $date): void
    {
        try {
            app(CommandBus::class)->dispatch(new UnblockDispatchDayCommand(
                date: substr($date, 0, 10),
                municipalityId: $this->selectedMunicipalityId(),
            ));
        } catch (CapacityException $exception) {
            Notification::make()
                ->title(__('Could not unblock the day'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Dispatch day unblocked'))
            ->success()
            ->send();
    }

    /**
     * Flujo "Activate slots": activa cupos por RANGO de fechas y por municipio. El operador elige uno o
     * todos los municipios, el rango from→to, los días de la semana activos y las ventanas (franja +
     * unidad + cantidad). Despacha ActivateCapacityWindowCommand; la materialización de buckets es
     * asíncrona (job troceado), así que solo se confirma que quedó encolado.
     */
    public function activateSlotsAction(): Action
    {
        return Action::make('activate_slots')
            ->label(__('Activate slots'))
            ->icon('heroicon-o-bolt')
            ->modalHeading(__('Activate dispatch slots for a date range'))
            ->modalDescription(__('Open slots for a date range (e.g. through November), by weekday and time window, for one or all municipalities. Buckets are generated in the background.'))
            ->modalSubmitActionLabel(__('Activate slots'))
            ->fillForm(fn (): array => [
                'all_municipalities' => false,
                'municipality_ids' => array_values(array_filter([$this->selectedMunicipalityId()], static fn (?string $v): bool => $v !== null)),
                'from' => date('Y-m-d'),
                'weekdays' => array_keys($this->weekdayOptions()),
                'windows' => [['time_slot' => '08:00-12:00', 'unit' => CapacityUnit::Deliveries->value, 'quantity' => null]],
            ])
            ->schema($this->activateFormComponents())
            ->action(function (array $data): void {
                $this->activateSlots($data);
            });
    }

    /**
     * Flujo "Block a day": bloquea un día (feriado u otro motivo) para el municipio del filtro o global.
     * Se precarga con el municipio elegido; si se abre desde un día concreto del calendario, con esa fecha.
     */
    public function blockDayAction(): Action
    {
        return Action::make('block_day')
            ->label(__('Block a day'))
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->modalHeading(__('Block a dispatch day'))
            ->modalDescription(__('Mark a day as a holiday or otherwise closed. Leave the municipality empty to block the whole network.'))
            ->modalSubmitActionLabel(__('Block day'))
            ->fillForm(fn (array $arguments): array => [
                'blackout_date' => is_string($arguments['date'] ?? null) ? substr($arguments['date'], 0, 10) : date('Y-m-d'),
                'municipality_id' => $this->selectedMunicipalityId(),
            ])
            ->schema([
                DatePicker::make('blackout_date')
                    ->label(__('Date'))
                    ->native(false)
                    ->required(),
                Select::make('municipality_id')
                    ->label(__('Municipality'))
                    ->helperText(__('Leave empty to block the whole network (global).'))
                    ->placeholder(__('Global (all municipalities)'))
                    ->native(false)
                    ->searchable()
                    ->options(static fn (): array => MunicipalityOptions::all()),
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('e.g. National holiday')),
            ])
            ->action(function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new BlockDispatchDayCommand(
                        date: substr((string) ($data['blackout_date'] ?? ''), 0, 10),
                        municipalityId: $this->blankToNull($data['municipality_id'] ?? null),
                        reason: trim((string) ($data['reason'] ?? '')),
                        blockedBy: $this->currentUserId(),
                    ));
                } catch (CapacityException $exception) {
                    Notification::make()
                        ->title(__('Could not block the day'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Dispatch day blocked'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Generate calendar": materializa el calendario de cupos del horizonte a partir de las reglas
     * vigentes. Invoca el handler directo (orquesta DefineCapacity por cupo faltante) y reporta cuántos
     * se crearon y cuántos ya existían.
     */
    public function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('Generate calendar'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading(__('Generate the dispatch calendar'))
            ->modalDescription(__('Create the capacity buckets the current rules prescribe for the horizon. Existing buckets are left untouched.'))
            ->schema([
                DatePicker::make('from')
                    ->label(__('Start date'))
                    ->native(false)
                    ->default(date('Y-m-d'))
                    ->helperText(__('First day to generate from. Defaults to today.')),
                TextInput::make('days')
                    ->label(__('Service-day horizon'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(CapacityRuleset::MAX_HORIZON_DAYS)
                    ->helperText(__('How many service days ahead to generate. Leave empty to use the ruleset horizon.')),
            ])
            ->action(function (array $data): void {
                $from = isset($data['from']) && is_string($data['from']) && $data['from'] !== ''
                    ? substr($data['from'], 0, 10)
                    : null;
                $days = isset($data['days']) && is_numeric($data['days']) ? (int) $data['days'] : null;

                /** @var GenerateDispatchCalendarHandler $handler */
                $handler = app(GenerateDispatchCalendarHandler::class);

                try {
                    $handler->handle(new GenerateDispatchCalendar($from, $days));
                } catch (CapacityException $exception) {
                    Notification::make()
                        ->title(__('Could not generate the calendar'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                // Deja la vista en la semana del primer día generado para ver el resultado al instante.
                if ($from !== null) {
                    $this->weekStart = $this->mondayOf($from);
                }

                Notification::make()
                    ->title(__('Dispatch calendar generated'))
                    ->body(__(':created buckets created, :skipped already present.', [
                        'created' => $handler->lastCreated(),
                        'skipped' => $handler->lastSkipped(),
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Edit rules": publica una versión nueva de las reglas de cupo (settings), sin pisar la
     * anterior. El formulario se precarga con las reglas vigentes; al guardar se valida el conjunto
     * completo (CapacityRuleset::fromArray) antes de despachar SetSetting al bus.
     */
    public function editRulesAction(): Action
    {
        return Action::make('edit_rules')
            ->label(__('Edit rules'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('gray')
            ->modalHeading(__('Edit capacity rules'))
            ->modalDescription(__('Publish a new version of the dispatch rules. Previous versions are kept as history.'))
            ->modalSubmitActionLabel(__('Publish new version'))
            ->fillForm(fn (): array => $this->currentRulesFormState())
            ->schema($this->rulesFormComponents())
            ->action(function (array $data): void {
                $value = $this->formStateToRuleValue($data);

                try {
                    // Valida el conjunto completo antes de publicar: una regla inválida no se versiona.
                    CapacityRuleset::fromArray($value);
                } catch (CapacityException $exception) {
                    Notification::make()
                        ->title(__('Invalid rules'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                app(CommandBus::class)->dispatch(new SetSettingCommand(
                    key: SettingsCapacityRulesetProvider::SETTING_KEY,
                    value: $value,
                    effectiveFrom: null,
                    createdBy: $this->currentUserId(),
                ));

                Notification::make()
                    ->title(__('Capacity rules published'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $municipalityId = $this->selectedMunicipalityId();

        return [
            'weekStart' => $this->weekStart,
            'weekEnd' => $this->weekEnd(),
            'week' => $this->buildWeek($municipalityId),
            'municipalityId' => $municipalityId,
            'municipalityLabel' => MunicipalityOptions::labelFor($municipalityId),
            'activeHorizon' => $this->activeHorizon($municipalityId),
            'windows' => $this->configuredWindows(),
            'schedule' => $this->scheduleSummary(),
            'unitLabels' => $this->unitLabels(),
            'history' => $this->ruleHistory(),
        ];
    }

    /**
     * Ejecuta la activación por rango a partir del estado del formulario: resuelve los municipios (uno,
     * varios o todos), normaliza días/ventanas, valida y despacha ActivateCapacityWindowCommand.
     *
     * @param array<string, mixed> $data
     */
    private function activateSlots(array $data): void
    {
        $all = (bool) ($data['all_municipalities'] ?? false);
        $municipalityIds = $all
            ? MunicipalityOptions::ids()
            : $this->toStringList($data['municipality_ids'] ?? []);

        if ($municipalityIds === []) {
            Notification::make()
                ->title(__('Pick at least one municipality'))
                ->body(__('Choose one or more municipalities, or turn on "Apply to all municipalities".'))
                ->danger()
                ->send();

            return;
        }

        $weekdays = array_values(array_map(intval(...), $this->toList($data['weekdays'] ?? [])));
        $windows = $this->formToActivationWindows($data['windows'] ?? []);

        if ($windows === []) {
            Notification::make()
                ->title(__('Add at least one time window'))
                ->body(__('Each window needs a time slot, a unit and a quantity.'))
                ->danger()
                ->send();

            return;
        }

        $from = substr((string) ($data['from'] ?? ''), 0, 10);
        $to = substr((string) ($data['to'] ?? ''), 0, 10);

        try {
            app(CommandBus::class)->dispatch(new ActivateCapacityWindowCommand(
                municipalityIds: $municipalityIds,
                fromDate: $from,
                toDate: $to,
                weekdays: $weekdays,
                windows: $windows,
                activatedBy: $this->currentUserId(),
            ));
        } catch (CapacityException $exception) {
            Notification::make()
                ->title(__('Could not activate slots'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Slot activation queued'))
            ->body(__('Slots for :count municipality(ies) are being activated in the background.', [
                'count' => count($municipalityIds),
            ]))
            ->success()
            ->send();
    }

    /**
     * Componentes del formulario de activación por rango. Municipios buscables y native(false); un toggle
     * "todos"; rango de fechas; días de la semana (los 7); y un repeater de ventanas (franja/unidad/cantidad).
     *
     * @return array<int, Component>
     */
    private function activateFormComponents(): array
    {
        return [
            Toggle::make('all_municipalities')
                ->label(__('Apply to all municipalities'))
                ->helperText(__('When on, the municipality picker is ignored and every municipality is activated.')),
            Select::make('municipality_ids')
                ->label(__('Municipalities'))
                ->native(false)
                ->searchable()
                ->multiple()
                ->options(static fn (): array => MunicipalityOptions::all())
                ->helperText(__('Ignored when "Apply to all municipalities" is on.')),
            DatePicker::make('from')
                ->label(__('From'))
                ->native(false)
                ->required(),
            DatePicker::make('to')
                ->label(__('To'))
                ->native(false)
                ->required()
                ->helperText(__('Last day to activate, e.g. the end of November.')),
            CheckboxList::make('weekdays')
                ->label(__('Active weekdays'))
                ->options($this->weekdayOptions())
                ->columns(4)
                ->required()
                ->helperText(__('Only these weekdays get slots. Blocked days are skipped automatically.')),
            Repeater::make('windows')
                ->label(__('Time windows'))
                ->schema([
                    TextInput::make('time_slot')
                        ->label(__('Time window'))
                        ->placeholder('08:00-12:00')
                        ->required()
                        ->maxLength(11)
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'),
                    Select::make('unit')
                        ->label(__('Unit'))
                        ->native(false)
                        ->required()
                        ->default(CapacityUnit::Deliveries->value)
                        ->options($this->unitOptions()),
                    TextInput::make('quantity')
                        ->label(__('Quantity'))
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.001),
                ])
                ->minItems(1)
                ->helperText(__('Each window opens that much capacity per eligible day.')),
        ];
    }

    /**
     * Repeater de ventanas → forma primitiva que espera ActivateCapacityWindowCommand. Descarta filas
     * incompletas en silencio.
     *
     * @return list<array{time_slot: string, unit: string, quantity: float}>
     */
    private function formToActivationWindows(mixed $raw): array
    {
        $windows = [];
        foreach ($this->toList($raw) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slot = is_string($item['time_slot'] ?? null) ? trim($item['time_slot']) : '';
            $unit = is_string($item['unit'] ?? null) ? $item['unit'] : '';
            $quantity = $item['quantity'] ?? null;
            if ($slot === '' || $unit === '' || !is_numeric($quantity)) {
                continue;
            }
            $windows[] = ['time_slot' => $slot, 'unit' => $unit, 'quantity' => (float) $quantity];
        }

        return $windows;
    }

    /**
     * Construye los siete días de la semana mirada para el municipio elegido: por cada día, su
     * disponibilidad (bloqueo/horizonte) y las ventanas con total/usado/disponible agregados por scope.
     *
     * @return list<array<string, mixed>>
     */
    private function buildWeek(?string $municipalityId): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);

        $start = $this->mondayOf($this->weekStart);
        $today = date('Y-m-d');
        $weekdayNames = $this->weekdayShortNames();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $timestamp = strtotime($start.' '.$i.' day');
            if ($timestamp === false) {
                continue;
            }
            $date = date('Y-m-d', $timestamp);
            $weekday = (int) date('N', $timestamp);

            /** @var DispatchDayAvailabilityView $availability */
            $availability = $queryBus->ask(new CheckDispatchDayQuery($date, $municipalityId));
            /** @var list<CapacityView> $buckets */
            $buckets = $queryBus->ask(new ListBucketsByDateQuery($date, $municipalityId));

            $days[] = [
                'date' => $date,
                'weekday' => $weekday,
                'weekday_label' => $weekdayNames[$weekday] ?? (string) $weekday,
                'is_today' => $date === $today,
                'blocked' => $availability->blocked,
                'reason' => $availability->reason,
                'within_horizon' => $availability->withinHorizon,
                'windows' => $this->aggregateWindows($buckets),
            ];
        }

        return $days;
    }

    /**
     * Agrega los cupos de un día por (franja, unidad), sumando total/usado entre scopes (p. ej. varias
     * zonas de un municipio). Cada fila lleva total/usado/disponible y si está llena.
     *
     * @param  list<CapacityView>         $buckets
     * @return list<array<string, mixed>>
     */
    private function aggregateWindows(array $buckets): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($buckets as $bucket) {
            $key = $bucket->timeSlot.'|'.$bucket->unit;
            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'time_slot' => $bucket->timeSlot,
                    'unit' => $bucket->unit,
                    'total' => 0.0,
                    'used' => 0.0,
                ];
            }
            $byKey[$key]['total'] = (float) $byKey[$key]['total'] + $bucket->total;
            $byKey[$key]['used'] = (float) $byKey[$key]['used'] + $bucket->used;
        }

        $rows = [];
        foreach ($byKey as $row) {
            $total = (float) $row['total'];
            $used = (float) $row['used'];
            $available = $total - $used;
            $rows[] = [
                'time_slot' => $row['time_slot'],
                'unit' => $row['unit'],
                'total' => $total,
                'used' => $used,
                'available' => $available,
                'full' => $available <= 0.0,
            ];
        }

        // Orden estable por franja y unidad.
        usort($rows, static fn (array $a, array $b): int => [$a['time_slot'], $a['unit']] <=> [$b['time_slot'], $b['unit']]);

        return $rows;
    }

    /**
     * Horizonte activo de cupos del alcance (municipio, o global si no hay municipio): hasta qué fecha hay
     * cupos activados. Devuelve null si nunca se activó ese alcance.
     *
     * @return array{active_until: string, global: bool}|null
     */
    private function activeHorizon(?string $municipalityId): ?array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var ActiveHorizonView|null $horizon */
        $horizon = $queryBus->ask(new GetActiveHorizonQuery($municipalityId));

        if ($horizon === null) {
            return null;
        }

        return ['active_until' => $horizon->activeUntil, 'global' => $horizon->global];
    }

    /**
     * Componentes del formulario de reglas de cupo. Cubre días de servicio, corte, horizonte, feriados,
     * ventanas por defecto y por día de la semana, cupo por unidad (y por franja) y los scopes a
     * materializar. Todo texto visible en inglés; los selects son native(false) (regla 5).
     *
     * @return array<int, Component>
     */
    private function rulesFormComponents(): array
    {
        return [
            CheckboxList::make('service_days')
                ->label(__('Service days'))
                ->options($this->weekdayOptions())
                ->columns(3)
                ->helperText(__('Days of the week deliveries are offered.')),
            TextInput::make('order_cutoff')
                ->label(__('Order cutoff'))
                ->placeholder('12:00')
                ->required()
                ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                ->helperText(__('Orders taken after this time ship the next service day. 24h HH:MM.')),
            TextInput::make('horizon_days')
                ->label(__('Horizon (service days)'))
                ->numeric()
                ->required()
                ->minValue(1)
                ->maxValue(CapacityRuleset::MAX_HORIZON_DAYS),
            TagsInput::make('windows')
                ->label(__('Default time windows'))
                ->placeholder('08:00-12:00')
                ->helperText(__('Same-day windows offered on service days, e.g. 08:00-12:00.')),
            Repeater::make('windows_by_weekday')
                ->label(__('Windows by weekday (overrides)'))
                ->schema([
                    Select::make('weekday')
                        ->label(__('Weekday'))
                        ->native(false)
                        ->options($this->weekdayOptions())
                        ->required(),
                    TagsInput::make('windows')
                        ->label(__('Windows'))
                        ->placeholder('08:00-12:00'),
                ])
                ->helperText(__('Replace the default windows on specific weekdays.'))
                ->defaultItems(0)
                ->collapsed(),
            TagsInput::make('holidays')
                ->label(__('Holidays'))
                ->placeholder('2026-01-01')
                ->helperText(__('Dates (Y-m-d) excluded even if they fall on a service day.')),
            TextInput::make('total_deliveries')
                ->label(__('Default deliveries per window'))
                ->numeric()
                ->minValue(0)
                ->step(0.001),
            TextInput::make('total_weight')
                ->label(__('Default weight per window (kg)'))
                ->numeric()
                ->minValue(0)
                ->step(0.001),
            TextInput::make('total_volume')
                ->label(__('Default volume per window (m³)'))
                ->numeric()
                ->minValue(0)
                ->step(0.001),
            Repeater::make('totals_by_slot')
                ->label(__('Per-window capacity (overrides)'))
                ->schema([
                    Select::make('unit')
                        ->label(__('Unit'))
                        ->native(false)
                        ->options($this->unitOptions())
                        ->required(),
                    TextInput::make('slot')
                        ->label(__('Time window'))
                        ->placeholder('08:00-12:00')
                        ->required()
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'),
                    TextInput::make('total')
                        ->label(__('Total'))
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->step(0.001),
                ])
                ->helperText(__('Fine-tune a unit total for a specific window; wins over the default.'))
                ->defaultItems(0)
                ->collapsed(),
            Repeater::make('scopes')
                ->label(__('Scopes to generate'))
                ->schema([
                    Select::make('node_id')
                        ->label(__('Node'))
                        ->native(false)
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => NodeRecord::query()
                            ->where(static function (Builder $query) use ($search): void {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(static fn (NodeRecord $record): array => [$record->id => "{$record->code} — {$record->name}"])
                            ->all())
                        ->getOptionLabelUsing(static function (string $value): ?string {
                            $record = NodeRecord::query()->find($value);

                            return $record instanceof NodeRecord ? "{$record->code} — {$record->name}" : null;
                        }),
                    Select::make('zone_id')
                        ->label(__('Zone'))
                        ->native(false)
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => ZoneRecord::query()
                            ->where('name', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(static fn (ZoneRecord $record): array => [$record->id => $record->name])
                            ->all())
                        ->getOptionLabelUsing(static function (string $value): ?string {
                            $record = ZoneRecord::query()->find($value);

                            return $record instanceof ZoneRecord ? $record->name : null;
                        }),
                ])
                ->helperText(__('Each scope is a node OR a zone. The calendar is generated for every scope listed.'))
                ->defaultItems(0)
                ->collapsed(),
        ];
    }

    /**
     * Estado inicial del formulario de reglas, a partir de las reglas vigentes (settings o config).
     *
     * @return array<string, mixed>
     */
    private function currentRulesFormState(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var array<string, mixed> $rules */
        $rules = $queryBus->ask(new GetCapacityRulesetQuery);

        $totals = is_array($rules['totals'] ?? null) ? $rules['totals'] : [];
        $windowsByWeekday = is_array($rules['windows_by_weekday'] ?? null) ? $rules['windows_by_weekday'] : [];
        $totalsBySlot = is_array($rules['totals_by_slot'] ?? null) ? $rules['totals_by_slot'] : [];

        return [
            'service_days' => is_array($rules['service_days'] ?? null) ? array_values($rules['service_days']) : [],
            'order_cutoff' => is_string($rules['order_cutoff'] ?? null) ? $rules['order_cutoff'] : '',
            'horizon_days' => is_numeric($rules['horizon_days'] ?? null) ? (int) $rules['horizon_days'] : null,
            'windows' => is_array($rules['windows'] ?? null) ? array_values($rules['windows']) : [],
            'windows_by_weekday' => $this->windowsByWeekdayToForm($windowsByWeekday),
            'holidays' => is_array($rules['holidays'] ?? null) ? array_values($rules['holidays']) : [],
            'total_deliveries' => $totals[CapacityUnit::Deliveries->value] ?? null,
            'total_weight' => $totals[CapacityUnit::Weight->value] ?? null,
            'total_volume' => $totals[CapacityUnit::Volume->value] ?? null,
            'totals_by_slot' => $this->totalsBySlotToForm($totalsBySlot),
            'scopes' => is_array($rules['scopes'] ?? null) ? array_values($rules['scopes']) : [],
        ];
    }

    /**
     * Traduce el estado del formulario de reglas a la forma primitiva que settings versiona.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function formStateToRuleValue(array $data): array
    {
        $totals = [];
        foreach ([
            CapacityUnit::Deliveries->value => $data['total_deliveries'] ?? null,
            CapacityUnit::Weight->value => $data['total_weight'] ?? null,
            CapacityUnit::Volume->value => $data['total_volume'] ?? null,
        ] as $unit => $value) {
            if (is_numeric($value)) {
                $totals[$unit] = (float) $value;
            }
        }

        return [
            'service_days' => array_map(intval(...), $this->toList($data['service_days'] ?? [])),
            'order_cutoff' => is_string($data['order_cutoff'] ?? null) ? trim($data['order_cutoff']) : '',
            'horizon_days' => is_numeric($data['horizon_days'] ?? null) ? (int) $data['horizon_days'] : 0,
            'holidays' => $this->toStringList($data['holidays'] ?? []),
            'windows' => $this->toStringList($data['windows'] ?? []),
            'windows_by_weekday' => $this->formToWindowsByWeekday($data['windows_by_weekday'] ?? []),
            'totals' => $totals,
            'totals_by_slot' => $this->formToTotalsBySlot($data['totals_by_slot'] ?? []),
            'scopes' => $this->formToScopes($data['scopes'] ?? []),
        ];
    }

    /**
     * Repeater de ventanas por día → mapa weekday => list<string>.
     *
     * @return array<int, list<string>>
     */
    private function formToWindowsByWeekday(mixed $raw): array
    {
        $map = [];
        foreach ($this->toList($raw) as $item) {
            if (!is_array($item) || !is_numeric($item['weekday'] ?? null)) {
                continue;
            }
            $map[(int) $item['weekday']] = $this->toStringList($item['windows'] ?? []);
        }

        return $map;
    }

    /**
     * Mapa weekday => list<string> → items del repeater.
     *
     * @param  array<array-key, mixed>                          $raw
     * @return list<array{weekday: int, windows: list<string>}>
     */
    private function windowsByWeekdayToForm(array $raw): array
    {
        $items = [];
        foreach ($raw as $weekday => $windows) {
            if (!is_numeric($weekday)) {
                continue;
            }
            $items[] = ['weekday' => (int) $weekday, 'windows' => $this->toStringList($windows)];
        }

        return $items;
    }

    /**
     * Repeater de cupos por franja → mapa unidad => (franja => total).
     *
     * @return array<string, array<string, float>>
     */
    private function formToTotalsBySlot(mixed $raw): array
    {
        $map = [];
        foreach ($this->toList($raw) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $unit = is_string($item['unit'] ?? null) ? $item['unit'] : null;
            $slot = is_string($item['slot'] ?? null) ? trim($item['slot']) : null;
            $total = $item['total'] ?? null;
            if ($unit === null || $slot === null || $slot === '' || !is_numeric($total)) {
                continue;
            }
            $map[$unit] ??= [];
            $map[$unit][$slot] = (float) $total;
        }

        return $map;
    }

    /**
     * Mapa unidad => (franja => total) → items del repeater.
     *
     * @param  array<array-key, mixed>                               $raw
     * @return list<array{unit: string, slot: string, total: float}>
     */
    private function totalsBySlotToForm(array $raw): array
    {
        $items = [];
        foreach ($raw as $unit => $bySlot) {
            if (!is_string($unit) || !is_array($bySlot)) {
                continue;
            }
            foreach ($bySlot as $slot => $total) {
                if (!is_string($slot) || !is_numeric($total)) {
                    continue;
                }
                $items[] = ['unit' => $unit, 'slot' => $slot, 'total' => (float) $total];
            }
        }

        return $items;
    }

    /**
     * Repeater de scopes → lista de {node_id, zone_id} (solo con exactamente uno poblado).
     *
     * @return list<array{node_id: string|null, zone_id: string|null}>
     */
    private function formToScopes(mixed $raw): array
    {
        $scopes = [];
        foreach ($this->toList($raw) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $nodeId = is_string($item['node_id'] ?? null) && trim($item['node_id']) !== '' ? trim($item['node_id']) : null;
            $zoneId = is_string($item['zone_id'] ?? null) && trim($item['zone_id']) !== '' ? trim($item['zone_id']) : null;
            // El dominio exige exactamente uno; los pares vacíos o ambiguos se descartan en silencio.
            if (($nodeId === null) === ($zoneId === null)) {
                continue;
            }
            $scopes[] = ['node_id' => $nodeId, 'zone_id' => $zoneId];
        }

        return $scopes;
    }

    /**
     * Historial de versiones de las reglas de cupo (de la más nueva a la más vieja), para mostrarlo.
     *
     * @return list<array{version: int, effective_from: string, created_at: string, created_by: string}>
     */
    private function ruleHistory(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<array<string, mixed>> $versions */
        $versions = $queryBus->ask(new GetSettingHistoryQuery(SettingsCapacityRulesetProvider::SETTING_KEY));

        $rows = [];
        foreach ($versions as $version) {
            $rows[] = [
                'version' => is_numeric($version['version'] ?? null) ? (int) $version['version'] : 0,
                'effective_from' => is_string($version['effective_from'] ?? null) ? $version['effective_from'] : '',
                'created_at' => is_string($version['created_at'] ?? null) ? $version['created_at'] : '',
                'created_by' => is_string($version['created_by'] ?? null) ? $version['created_by'] : '',
            ];
        }

        return $rows;
    }

    /**
     * Ventanas de franja configuradas (referencia para el operador).
     *
     * @return list<string>
     */
    private function configuredWindows(): array
    {
        /** @var Config $config */
        $config = app(Config::class);
        $windows = $config->get('logistics-capacity.dispatch_calendar.windows', []);

        return is_array($windows) ? array_values(array_map(strval(...), $windows)) : [];
    }

    /**
     * Resumen de las reglas de cupo vigentes, para mostrarlo junto al calendario.
     *
     * @return array<string, mixed>
     */
    private function scheduleSummary(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var array<string, mixed> $rules */
        $rules = $queryBus->ask(new GetCapacityRulesetQuery);

        $names = $this->weekdayShortNames();
        $days = is_array($rules['service_days'] ?? null) ? $rules['service_days'] : [];
        $scopes = is_array($rules['scopes'] ?? null) ? $rules['scopes'] : [];

        return [
            'business_days' => implode(', ', array_map(static fn ($d): string => (string) ($names[(int) $d] ?? $d), $days)),
            'order_cutoff' => is_string($rules['order_cutoff'] ?? null) ? $rules['order_cutoff'] : '',
            'horizon_days' => is_numeric($rules['horizon_days'] ?? null) ? (int) $rules['horizon_days'] : 0,
            'scopes_count' => count($scopes),
        ];
    }

    /**
     * Etiquetas legibles de cada unidad de capacidad.
     *
     * @return array<string, string>
     */
    private function unitLabels(): array
    {
        $labels = [];
        foreach (CapacityUnit::cases() as $unit) {
            $labels[$unit->value] = __($unit->label());
        }

        return $labels;
    }

    /**
     * Opciones día de la semana (1=Mon … 7=Sun) para los campos del formulario.
     *
     * @return array<int, string>
     */
    private function weekdayOptions(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }

    /**
     * Nombres cortos de día de la semana (1=Mon … 7=Sun), para el encabezado del calendario y el resumen.
     *
     * @return array<int, string>
     */
    private function weekdayShortNames(): array
    {
        return [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
    }

    /**
     * Opciones valor→etiqueta de las unidades de capacidad.
     *
     * @return array<string, string>
     */
    private function unitOptions(): array
    {
        $options = [];
        foreach (CapacityUnit::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Municipio elegido en el filtro, como string, o null (todos los municipios).
     */
    private function selectedMunicipalityId(): ?string
    {
        return $this->blankToNull($this->filters['municipality_id'] ?? null);
    }

    /**
     * Identificador del usuario autenticado (para auditoría), como string o null.
     */
    private function currentUserId(): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
    }

    /**
     * Lunes de la semana que contiene la fecha dada, en 'Y-m-d'. Cae a hoy si la fecha es inválida.
     */
    private function mondayOf(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            $timestamp = time();
        }
        $weekday = (int) date('N', $timestamp);
        $monday = strtotime('-'.($weekday - 1).' day', $timestamp);

        return date('Y-m-d', $monday === false ? $timestamp : $monday);
    }

    /**
     * Domingo de la semana mirada, en 'Y-m-d' (para mostrar el rango).
     */
    private function weekEnd(): string
    {
        $timestamp = strtotime($this->mondayOf($this->weekStart).' 6 day');

        return $timestamp === false ? $this->weekStart : date('Y-m-d', $timestamp);
    }

    /**
     * Convierte cadenas vacías a null.
     */
    private function blankToNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Normaliza cualquier valor a una lista (los repeaters/tags llegan como arreglos).
     *
     * @return list<mixed>
     */
    private function toList(mixed $raw): array
    {
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Normaliza un valor a una lista de strings no vacíos, recortados.
     *
     * @return list<string>
     */
    private function toStringList(mixed $raw): array
    {
        $out = [];
        foreach ($this->toList($raw) as $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        }

        return $out;
    }
}
