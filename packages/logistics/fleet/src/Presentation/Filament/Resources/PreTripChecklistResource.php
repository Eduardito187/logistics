<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\CreatePreTripChecklist;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ListPreTripChecklists;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ViewPreTripChecklist;

/**
 * Resource de administración del checklist pre-salida (inspección ejecutada sobre un vehículo).
 *
 * Filament usa PreTripInspectionModel para LECTURA; el alta despacha ConductPreTripChecklist por el
 * CommandBus. No se expone edición: una inspección es un registro inmutable de lo verificado ese día.
 * El resultado (apta / no apta) lo deriva el dominio de que el checklist quede completo.
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class PreTripChecklistResource extends Resource
{
    protected static ?string $model = PreTripInspectionModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 18;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Pre-trip checklist');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Pre-trip checklists');
    }

    /**
     * Título legible del registro: placa del vehículo y fecha de la inspección, nunca el UUID. Una
     * inspección no tiene un único campo humano, así que se compone desde la relación ya cargada.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof PreTripInspectionModel) {
            return null;
        }

        $plate = $record->vehicle->plate ?? '—';

        return "{$plate} · {$record->performed_on}";
    }

    /**
     * Eager-load de vehículo y chofer para que `vehicle.plate` y `driver.name` no disparen N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'driver']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->required()
                ->native(false)
                ->searchable()
                ->getSearchResultsUsing(static fn (string $search): array => VehicleModel::query()
                    ->where('plate', 'ilike', "%{$search}%")
                    ->orderBy('plate')
                    ->limit(50)
                    ->pluck('plate', 'id')
                    ->all())
                ->getOptionLabelUsing(static fn (string $value): ?string => VehicleModel::query()
                    ->whereKey($value)
                    ->value('plate')),

            Select::make('driver_id')
                ->label(__('Driver'))
                ->required()
                ->native(false)
                ->searchable()
                ->getSearchResultsUsing(static fn (string $search): array => DriverModel::query()
                    ->where('name', 'ilike', "%{$search}%")
                    ->orderBy('name')
                    ->limit(50)
                    ->pluck('name', 'id')
                    ->all())
                ->getOptionLabelUsing(static fn (string $value): ?string => DriverModel::query()
                    ->whereKey($value)
                    ->value('name')),

            DatePicker::make('performed_on')
                ->label(__('Performed on'))
                ->native(false)
                ->required()
                ->default(now()->format('Y-m-d'))
                ->format('Y-m-d'),

            // Cada fila es un ítem del checklist con su verificación; se convierte a mapa en la página.
            Repeater::make('checklist_items')
                ->label(__('Checklist items'))
                ->schema([
                    TextInput::make('item')
                        ->label(__('Item'))
                        ->required()
                        ->maxLength(100),
                    Toggle::make('checked')
                        ->label(__('Checked'))
                        ->default(false),
                ])
                ->columns(2)
                ->minItems(1)
                ->default([
                    ['item' => 'Tires', 'checked' => false],
                    ['item' => 'Brakes', 'checked' => false],
                    ['item' => 'Lights', 'checked' => false],
                    ['item' => 'Fuel level', 'checked' => false],
                    ['item' => 'Documents on board', 'checked' => false],
                ]),

            Textarea::make('notes')
                ->label(__('Notes'))
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Placa legible del vehículo vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                // Nombre legible del chofer vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('driver.name')
                    ->label(__('Driver'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('passed')
                    ->label(__('Result'))
                    ->badge()
                    ->color(static fn (bool $state): string => $state ? 'success' : 'danger')
                    ->formatStateUsing(static fn (bool $state): string => $state ? __('Passed') : __('Failed')),
                TextColumn::make('performed_on')
                    ->label(__('Performed on'))
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('passed')
                    ->label(__('Passed')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('performed_on', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('vehicle.plate')
                ->label(__('Vehicle'))
                ->placeholder('—'),
            TextEntry::make('driver.name')
                ->label(__('Driver'))
                ->placeholder('—'),
            IconEntry::make('passed')->label(__('Passed'))->boolean(),
            TextEntry::make('performed_on')->label(__('Performed on'))->date('Y-m-d'),
            TextEntry::make('items')
                ->label(__('Items'))
                // Muestra cada ítem con su estado de verificación (checked / pending).
                ->formatStateUsing(static function ($state): string {
                    if (!is_array($state) || $state === []) {
                        return '—';
                    }
                    $lines = [];
                    foreach ($state as $name => $checked) {
                        $lines[] = ((bool) $checked ? '✓ ' : '✗ ').$name;
                    }

                    return implode(' · ', $lines);
                }),
            TextEntry::make('notes')->label(__('Notes'))->placeholder('—'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPreTripChecklists::route('/'),
            'create' => CreatePreTripChecklist::route('/create'),
            'view' => ViewPreTripChecklist::route('/{record}'),
        ];
    }
}
