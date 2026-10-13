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
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendar;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendarHandler;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Calendario de despacho del admin: muestra, para un día elegido, los cupos de entrega por scope
 * (nodo o zona) y franja horaria, con total / usado / disponible, y el resumen de las reglas de cupo
 * vigentes (días de servicio, corte, ventanas). Desde la cabecera se editan las reglas (publicando una
 * versión nueva en settings, con historial) y se genera el calendario del horizonte a partir de ellas.
 *
 * Nota de arquitectura: los NÚMEROS salen del lado de lectura CQRS (QueryBus → ListBucketsByDateQuery
 * → CapacityView), nunca de un acceso directo al agregado. Editar reglas despacha SetSetting al bus (no
 * pisa versiones: crea la siguiente). Generar despacha DefineCapacity por cada cupo faltante. Los
 * nombres legibles de nodo/zona se resuelven desde las proyecciones de lectura (NodeRecord/ZoneRecord)
 * solo para presentación. Todo texto visible va en inglés (regla 5); comentarios en español (regla 6).
 */
final class DispatchCalendar extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 20;

    protected string $view = 'logistics-capacity::filament.pages.dispatch-calendar';

    /** Día que se está mirando, en 'Y-m-d'. Enlazado al selector de fecha de la vista. */
    public string $date = '';

    public function mount(): void
    {
        // Por defecto, el día de hoy.
        if ($this->date === '') {
            $this->date = date('Y-m-d');
        }
    }

    public static function getNavigationGroup(): string
    {
        return __('Capacity');
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
     * Acciones de cabecera: generar el calendario del horizonte y editar las reglas de cupo.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->editRulesAction(),
            $this->generateAction(),
        ];
    }

    /**
     * Avanza o retrocede el día mirado (botones del encabezado del calendario).
     */
    public function shiftDay(int $days): void
    {
        $timestamp = strtotime($this->date." {$days} day");
        // strtotime devuelve false si la fecha actual no es parseable: en ese caso no se mueve el día.
        if ($timestamp === false) {
            return;
        }

        $this->date = date('Y-m-d', $timestamp);
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

                // Deja la vista en el primer día generado para ver el resultado al instante.
                if ($from !== null) {
                    $this->date = $from;
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
        $date = $this->normalizedDate();

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);
        /** @var list<CapacityView> $buckets */
        $buckets = $queryBus->ask(new ListBucketsByDateQuery($date));

        return [
            'date' => $date,
            'windows' => $this->configuredWindows(),
            'scopes' => $this->groupByScope($buckets),
            'schedule' => $this->scheduleSummary(),
            'unitLabels' => $this->unitLabels(),
            'history' => $this->ruleHistory(),
        ];
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
     * Agrupa los cupos del día por scope (nodo o zona), y dentro de cada scope por franja. Cada celda
     * lleva total/usado/disponible por unidad.
     *
     * @param  list<CapacityView>                                                                      $buckets
     * @return list<array{key: string, label: string, kind: string, rows: list<array<string, mixed>>}>
     */
    private function groupByScope(array $buckets): array
    {
        $nodeNames = $this->nodeNames($buckets);
        $zoneNames = $this->zoneNames($buckets);

        /** @var array<string, array{key: string, label: string, kind: string, rows: list<array<string, mixed>>}> $scopes */
        $scopes = [];
        foreach ($buckets as $bucket) {
            if ($bucket->nodeId !== null) {
                $key = 'node:'.$bucket->nodeId;
                $label = $nodeNames[$bucket->nodeId] ?? $bucket->nodeId;
                $kind = __('Node');
            } else {
                $key = 'zone:'.((string) $bucket->zoneId);
                $label = $zoneNames[(string) $bucket->zoneId] ?? (string) $bucket->zoneId;
                $kind = __('Zone');
            }

            $scopes[$key] ??= ['key' => $key, 'label' => $label, 'kind' => $kind, 'rows' => []];
            $scopes[$key]['rows'][] = [
                'time_slot' => $bucket->timeSlot,
                'unit' => $bucket->unit,
                'total' => $bucket->total,
                'used' => $bucket->used,
                'available' => $bucket->available,
                'full' => $bucket->full,
            ];
        }

        // Orden estable: por etiqueta de scope, y dentro por franja y unidad.
        $ordered = array_values($scopes);
        usort($ordered, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        foreach ($ordered as &$scope) {
            usort($scope['rows'], static function (array $a, array $b): int {
                return [$a['time_slot'], $a['unit']] <=> [$b['time_slot'], $b['unit']];
            });
        }
        unset($scope);

        return $ordered;
    }

    /**
     * Nombres de nodo (code — name) para los ids presentes en los cupos.
     *
     * @param  list<CapacityView>    $buckets
     * @return array<string, string>
     */
    private function nodeNames(array $buckets): array
    {
        $ids = array_values(array_filter(array_map(static fn (CapacityView $b): ?string => $b->nodeId, $buckets)));
        if ($ids === []) {
            return [];
        }

        return NodeRecord::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(static fn (NodeRecord $n): array => [$n->id => "{$n->code} — {$n->name}"])
            ->all();
    }

    /**
     * Nombres de zona para los ids presentes en los cupos.
     *
     * @param  list<CapacityView>    $buckets
     * @return array<string, string>
     */
    private function zoneNames(array $buckets): array
    {
        $ids = array_values(array_filter(array_map(static fn (CapacityView $b): ?string => $b->zoneId, $buckets)));
        if ($ids === []) {
            return [];
        }

        return ZoneRecord::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'name'])
            ->mapWithKeys(static fn (ZoneRecord $z): array => [$z->id => $z->name])
            ->all();
    }

    /**
     * Ventanas de franja configuradas (columnas del calendario).
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

        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
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
     * Identificador del usuario autenticado (para auditoría), como string o null.
     */
    private function currentUserId(): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
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

    /**
     * Normaliza la fecha mirada a 'Y-m-d', cayendo a hoy si viene vacía o mal formada.
     */
    private function normalizedDate(): string
    {
        $time = strtotime($this->date);

        return $time === false ? date('Y-m-d') : date('Y-m-d', $time);
    }
}
