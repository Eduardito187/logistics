<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\CreateCrew;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\ListCrews;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\ViewCrew;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Resource de administración de tripulaciones (chofer + ayudantes + vehículo para una fecha).
 *
 * El chofer y el vehículo se muestran legibles vía las relaciones Eloquent de CrewModel
 * (`driver.name`, `vehicle.plate`), eager-cargadas en getEloquentQuery para evitar N+1. Los ayudantes
 * son entidades Helper de la flota, referenciadas por id en `helper_ids`. Filament usa CrewModel para
 * LECTURA; el alta despacha AssembleCrew por el CommandBus. No se expone edición: una tripulación se
 * arma para una fecha y no se modifica (se arma otra).
 *
 * Todo el texto visible va en inglés (regla 5 de CLAUDE.md).
 */
final class CrewResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = CrewModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 16;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Crew');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Crews');
    }

    /**
     * Título legible del registro: chofer, placa y fecha, nunca el UUID. Una tripulación no tiene un
     * único campo humano, así que se compone a partir de las relaciones ya cargadas.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof CrewModel) {
            return null;
        }

        $driver = $record->driver->name ?? '—';
        $plate = $record->vehicle->plate ?? '—';

        return "{$driver} · {$plate} · {$record->assembled_for}";
    }

    /**
     * Eager-load de chofer y vehículo para que `driver.name` y `vehicle.plate` no disparen N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver', 'vehicle']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('driver_id')
                ->label(__('Driver'))
                ->required()
                ->native(false)
                ->searchable()
                // Datos reales de la flota: búsqueda por nombre del chofer.
                ->getSearchResultsUsing(static fn (string $search): array => DriverModel::query()
                    ->where('name', 'ilike', "%{$search}%")
                    ->orderBy('name')
                    ->limit(50)
                    ->pluck('name', 'id')
                    ->all())
                ->getOptionLabelUsing(static fn (string $value): ?string => DriverModel::query()
                    ->whereKey($value)
                    ->value('name')),

            Select::make('helper_ids')
                ->label(__('Helpers'))
                ->multiple()
                ->native(false)
                ->searchable()
                // Ayudantes reales (entidad Helper), solo los activos y buscables por nombre.
                ->getSearchResultsUsing(static fn (string $search): array => HelperModel::query()
                    ->where('active', true)
                    ->where('name', 'ilike', "%{$search}%")
                    ->orderBy('name')
                    ->limit(50)
                    ->pluck('name', 'id')
                    ->all())
                ->getOptionLabelsUsing(static fn (array $values): array => HelperModel::query()
                    ->whereKey($values)
                    ->pluck('name', 'id')
                    ->all()),

            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->required()
                ->native(false)
                ->searchable()
                // Datos reales de la flota: búsqueda por placa.
                ->getSearchResultsUsing(static fn (string $search): array => VehicleModel::query()
                    ->where('plate', 'ilike', "%{$search}%")
                    ->orderBy('plate')
                    ->limit(50)
                    ->pluck('plate', 'id')
                    ->all())
                ->getOptionLabelUsing(static fn (string $value): ?string => VehicleModel::query()
                    ->whereKey($value)
                    ->value('plate')),

            DatePicker::make('assembled_for')
                ->label(__('Assembled for'))
                ->native(false)
                ->required()
                ->format('Y-m-d'),

            // Turno opcional: horas reales de salida y regreso. Si se fija, el alta valida la ventana
            // horaria, la jornada máxima y el descanso mínimo contra los demás turnos de cada integrante.
            DateTimePicker::make('shift_starts_at')
                ->label(__('Shift start'))
                ->native(false)
                ->seconds(false)
                ->requiredWith('shift_ends_at'),

            DateTimePicker::make('shift_ends_at')
                ->label(__('Shift end'))
                ->native(false)
                ->seconds(false)
                ->requiredWith('shift_starts_at')
                ->after('shift_starts_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nombre legible del chofer vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('driver.name')
                    ->label(__('Driver'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                // Placa legible del vehículo vía la relación Eloquent (eager-cargada), no el UUID.
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('helper_ids')
                    ->label(__('Helpers'))
                    ->formatStateUsing(static fn ($state): int => is_array($state) ? count($state) : 0),
                TextColumn::make('assembled_for')
                    ->label(__('Assembled for'))
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('assembled_for', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('driver.name')
                ->label(__('Driver'))
                ->placeholder('—'),
            TextEntry::make('vehicle.plate')
                ->label(__('Vehicle'))
                ->placeholder('—'),
            TextEntry::make('helper_ids')
                ->label(__('Helpers'))
                ->badge()
                ->placeholder('—')
                // Muestra el nombre real de cada ayudante (entidad Helper referenciada por id).
                ->formatStateUsing(static fn (string $state): string => HelperModel::query()
                    ->whereKey($state)
                    ->value('name') ?? '—'),
            TextEntry::make('assembled_for')->label(__('Assembled for'))->date('Y-m-d'),
            TextEntry::make('shift_starts_at')
                ->label(__('Shift start'))
                ->dateTime('Y-m-d H:i')
                ->placeholder('—'),
            TextEntry::make('shift_ends_at')
                ->label(__('Shift end'))
                ->dateTime('Y-m-d H:i')
                ->placeholder('—'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCrews::route('/'),
            'create' => CreateCrew::route('/create'),
            'view' => ViewCrew::route('/{record}'),
        ];
    }
}
