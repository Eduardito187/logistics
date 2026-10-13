<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages\ListCapacityBuckets;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages\ViewCapacityBucket;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin Filament de cupos de capacidad (capacity buckets): un tope por scope (nodo o zona), fecha,
 * franja horaria y unidad.
 *
 * Nota de arquitectura: Filament se apoya en un record Eloquent (CapacityBucketRecord) como proyección
 * de LECTURA para listar y ver. Toda MUTACIÓN de negocio —definir, consumir y liberar capacidad— se
 * expresa como un flujo transaccional que despacha el Command correspondiente al CommandBus CQRS; así
 * se respetan las invariantes del agregado y la publicación de eventos al outbox en la misma
 * transacción. El admin NUNCA escribe el record directamente (no hay Create/Edit CRUD pelado).
 *
 * Los selects de nodo y zona son buscables y se alimentan de los registros reales de los contextos
 * Network y Coverage, y las columnas muestran el código/nombre de la entidad relacionada en vez del
 * UUID crudo. Todo texto visible va en inglés (regla 5 del proyecto); comentarios en español (regla 6).
 *
 * @extends resource<CapacityBucketRecord>
 */
final class CapacityBucketResource extends Resource
{
    protected static ?string $model = CapacityBucketRecord::class;

    protected static ?int $navigationSort = 10;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-chart-bar-square';
    }

    public static function getNavigationGroup(): string
    {
        return __('Capacity');
    }

    public static function getModelLabel(): string
    {
        return __('Capacity bucket');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Capacity buckets');
    }

    /**
     * Título humano del registro (nunca el UUID): scope + fecha + franja + unidad, p. ej.
     * "Node LPZ-01 · 2026-10-13 09:00-13:00 · Deliveries".
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof CapacityBucketRecord) {
            return null;
        }

        $unitLabel = CapacityUnit::tryFrom($record->unit)?->label();
        $unit = $unitLabel !== null ? __($unitLabel) : $record->unit;

        return self::scopeLabel($record)." · {$record->slot_date} {$record->time_slot} · {$unit}";
    }

    /**
     * Eager-load de las relaciones de scope para que las columnas legibles no disparen N+1.
     *
     * @return Builder<CapacityBucketRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['node', 'zone']);
    }

    public static function form(Schema $schema): Schema
    {
        // El formulario comparte el mismo esquema que el flujo "Define capacity": definir un cupo es la
        // única alta posible y siempre pasa por el Command (no hay página de create con escritura directa).
        return $schema->components(self::defineFormComponents());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Scope legible: código/nombre del nodo o nombre de la zona (vía relación eager-loaded),
                // nunca el UUID. El tipo de scope va como descripción secundaria.
                TextColumn::make('scope')
                    ->label(__('Scope'))
                    ->state(static fn (CapacityBucketRecord $record): string => self::scopeLabel($record))
                    ->description(static fn (CapacityBucketRecord $record): string => $record->node_id !== null ? __('Node') : __('Zone'))
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        return $query
                            ->whereHas('node', static function (Builder $sub) use ($search): void {
                                /** @var Builder<NodeRecord> $sub */
                                $sub->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orWhereHas('zone', static function (Builder $sub) use ($search): void {
                                /** @var Builder<ZoneRecord> $sub */
                                $sub->where('name', 'like', "%{$search}%");
                            });
                    }),
                TextColumn::make('slot_date')
                    ->label(__('Slot date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('time_slot')
                    ->label(__('Time slot'))
                    ->sortable(),
                TextColumn::make('unit')
                    ->label(__('Unit'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(CapacityUnit::from($state)->label())),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                TextColumn::make('used')
                    ->label(__('Used'))
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),
                // Disponible = total - used; verde si queda cupo, rojo si está agotado.
                TextColumn::make('available')
                    ->label(__('Available'))
                    ->badge()
                    ->color(static fn (CapacityBucketRecord $record): string => self::availableOf($record) > 0 ? 'success' : 'danger')
                    ->state(static fn (CapacityBucketRecord $record): string => number_format(self::availableOf($record), 3, '.', '')),
                // Identidades UUID crudas: secundarias, ocultas por defecto (para depuración).
                TextColumn::make('node_id')
                    ->label(__('Node ID'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('zone_id')
                    ->label(__('Zone ID'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label(__('Bucket ID'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('unit')
                    ->label(__('Unit'))
                    ->native(false)
                    ->options(self::unitOptions()),
            ])
            ->defaultSort('slot_date', 'desc')
            ->recordActions([
                ViewAction::make(),
                // Flujos transaccionales por fila: el bucket ya está identificado por su clave natural,
                // así que solo se pide la cantidad y se despacha el Command correspondiente.
                self::consumeAction(),
                self::releaseAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCapacityBuckets::route('/'),
            'view' => ViewCapacityBucket::route('/{record}'),
        ];
    }

    /**
     * Flujo "Define capacity": da de alta un cupo nuevo despachando DefineCapacityCommand al CommandBus.
     * Se expone como acción de cabecera del listado (y como botón en la página List).
     */
    public static function defineAction(): Action
    {
        return Action::make('define')
            ->label(__('Define capacity'))
            ->icon('heroicon-o-plus-circle')
            ->modalHeading(__('Define a capacity bucket'))
            ->modalDescription(__('Create a capacity limit for a node or a zone on a given date, time slot and unit.'))
            ->schema(self::defineFormComponents())
            ->action(static function (array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new DefineCapacityCommand(
                        nodeId: self::blankToNull($data['node_id'] ?? null),
                        zoneId: self::blankToNull($data['zone_id'] ?? null),
                        date: (string) $data['slot_date'],
                        timeSlot: (string) $data['time_slot'],
                        unit: (string) $data['unit'],
                        total: (float) $data['total'],
                    ));
                } catch (CapacityException $exception) {
                    self::failure(__('Could not define capacity'), $exception);

                    return;
                }

                Notification::make()
                    ->title(__('Capacity defined'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Consume capacity": imputa una cantidad al cupo de la fila despachando ConsumeCapacityCommand.
     */
    public static function consumeAction(): Action
    {
        return Action::make('consume')
            ->label(__('Consume'))
            ->icon('heroicon-o-arrow-trending-down')
            ->color('warning')
            ->modalHeading(__('Consume capacity'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount to consume'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.001),
            ])
            ->action(static function (CapacityBucketRecord $record, array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new ConsumeCapacityCommand(
                        nodeId: $record->node_id,
                        zoneId: $record->zone_id,
                        date: self::recordDate($record),
                        timeSlot: (string) $record->time_slot,
                        unit: (string) $record->unit,
                        amount: (float) $data['amount'],
                    ));
                } catch (CapacityException $exception) {
                    self::failure(__('Could not consume capacity'), $exception);

                    return;
                }

                Notification::make()
                    ->title(__('Capacity consumed'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo "Release capacity": devuelve una cantidad al cupo de la fila despachando ReleaseCapacityCommand.
     */
    public static function releaseAction(): Action
    {
        return Action::make('release')
            ->label(__('Release'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('success')
            ->modalHeading(__('Release capacity'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount to release'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.001),
            ])
            ->action(static function (CapacityBucketRecord $record, array $data): void {
                try {
                    app(CommandBus::class)->dispatch(new ReleaseCapacityCommand(
                        nodeId: $record->node_id,
                        zoneId: $record->zone_id,
                        date: self::recordDate($record),
                        timeSlot: (string) $record->time_slot,
                        unit: (string) $record->unit,
                        amount: (float) $data['amount'],
                    ));
                } catch (CapacityException $exception) {
                    self::failure(__('Could not release capacity'), $exception);

                    return;
                }

                Notification::make()
                    ->title(__('Capacity released'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Componentes del formulario de definición de cupo. Scope: exactamente uno de nodo/zona (regla del
     * dominio); ambos selects son buscables y se alimentan de los registros reales de Network y Coverage.
     *
     * @return array<int, Component>
     */
    private static function defineFormComponents(): array
    {
        return [
            Select::make('node_id')
                ->label(__('Node'))
                ->helperText(__('Set a node OR a zone, not both: a bucket is scoped to exactly one of them.'))
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
                ->helperText(__('Set a zone OR a node, not both: a bucket is scoped to exactly one of them.'))
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
            DatePicker::make('slot_date')
                ->label(__('Slot date'))
                ->native(false)
                ->required(),
            TextInput::make('time_slot')
                ->label(__('Time slot'))
                ->helperText(__('Same-day window in 24h format, e.g. 09:00-13:00.'))
                ->placeholder('09:00-13:00')
                ->required()
                ->maxLength(11)
                ->regex('/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'),
            Select::make('unit')
                ->label(__('Unit'))
                ->native(false)
                ->required()
                ->default(CapacityUnit::Deliveries->value)
                ->options(self::unitOptions()),
            TextInput::make('total')
                ->label(__('Total capacity'))
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(0.001),
        ];
    }

    /**
     * Etiqueta humana del scope de un cupo: código y nombre del nodo, o nombre de la zona. Usa las
     * relaciones eager-loaded; cae al UUID solo si la entidad relacionada ya no existe.
     */
    private static function scopeLabel(CapacityBucketRecord $record): string
    {
        if ($record->node_id !== null) {
            $node = $record->node;

            return $node instanceof NodeRecord ? "{$node->code} — {$node->name}" : (string) $record->node_id;
        }

        if ($record->zone_id !== null) {
            $zone = $record->zone;

            return $zone instanceof ZoneRecord ? $zone->name : (string) $record->zone_id;
        }

        return '—';
    }

    /**
     * Capacidad disponible de un cupo (total - used), como float para badges y comparaciones.
     */
    private static function availableOf(CapacityBucketRecord $record): float
    {
        return (float) $record->total - (float) $record->used;
    }

    /**
     * Normaliza la fecha del record a 'Y-m-d' (lo que exige SlotDate), venga como string o como Carbon.
     */
    private static function recordDate(CapacityBucketRecord $record): string
    {
        return substr((string) $record->slot_date, 0, 10);
    }

    /**
     * Convierte cadenas vacías a null (los selects de scope opcionales mandan '' cuando no se elige).
     */
    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Notificación de error de un flujo: muestra el motivo de dominio sin tumbar el request.
     */
    private static function failure(string $title, CapacityException $exception): void
    {
        Notification::make()
            ->title($title)
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    /**
     * Mapa valor→etiqueta en inglés de las unidades de capacidad.
     *
     * @return array<string, string>
     */
    private static function unitOptions(): array
    {
        $options = [];
        foreach (CapacityUnit::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }
}
