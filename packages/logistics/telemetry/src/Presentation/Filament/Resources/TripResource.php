<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages\ListTrips;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages\ViewTrip;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;

/**
 * Admin de trips (tramos de conducción). SOLO LECTURA: los trips los materializa la ingesta de
 * telemetría; aquí no se crean ni editan. Las magnitudes (km, minutos) se derivan de las columnas
 * crudas (metros, segundos) solo para presentación, igual que el ReadModel TripView.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class TripResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = TripRecord::class;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 50;
    }

    /**
     * Título legible del trip: la placa del vehículo, nunca el UUID (regla: ningún UUID visible).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof TripRecord) {
            return null;
        }

        return VehiclePlates::label($record->vehicle_id) ?? '—';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-flag';
    }

    public static function getModelLabel(): string
    {
        return __('Trip');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Trips');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Etiquetas humanas (inglés) de cada estado de trip. Las claves son los valores del enum.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (TripStatus::cases() as $status) {
            $options[$status->value] = __($status->label());
        }

        return $options;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Trip'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? '—'),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::statusOptions()[$state] ?? $state)
                        ->color(static fn (string $state): string => $state === TripStatus::Active->value ? 'success' : 'gray'),
                    TextEntry::make('started_at')
                        ->label(__('Started at'))
                        ->dateTime(),
                    TextEntry::make('ended_at')
                        ->label(__('Ended at'))
                        ->dateTime()
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make(__('Metrics'))
                ->schema([
                    TextEntry::make('distance_m')
                        ->label(__('Distance'))
                        ->formatStateUsing(static fn ($state): string => number_format(((float) $state) / 1000, 2).' km'),
                    TextEntry::make('duration_s')
                        ->label(__('Duration'))
                        ->formatStateUsing(static fn ($state): string => number_format(((int) $state) / 60, 0).' min'),
                    TextEntry::make('moving_s')
                        ->label(__('Moving'))
                        ->formatStateUsing(static fn ($state): string => number_format(((int) $state) / 60, 0).' min'),
                    TextEntry::make('idle_s')
                        ->label(__('Idle'))
                        ->formatStateUsing(static fn ($state): string => number_format(((int) $state) / 60, 0).' min'),
                    TextEntry::make('max_speed_kph')
                        ->label(__('Max speed'))
                        ->formatStateUsing(static fn ($state): string => number_format((float) $state, 0).' km/h'),
                    TextEntry::make('ping_count')
                        ->label(__('Pings'))
                        ->numeric(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? '—')
                    ->sortable(),
                // UUID crudo del vehículo: oculto por defecto, disponible como columna toggleable para
                // depurar o cruzar datos. La placa legible vive en la columna "Vehicle" de arriba.
                TextColumn::make('vehicle_uuid')
                    ->label(__('Vehicle ID'))
                    ->getStateUsing(static fn (TripRecord $record): string => $record->vehicle_id)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->label(__('Started at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('distance_m')
                    ->label(__('Distance'))
                    ->getStateUsing(static fn (TripRecord $record): string => number_format($record->distance_m / 1000, 2).' km')
                    ->sortable(),
                TextColumn::make('duration_s')
                    ->label(__('Duration'))
                    ->getStateUsing(static fn (TripRecord $record): string => number_format($record->duration_s / 60, 0).' min')
                    ->sortable(),
                TextColumn::make('moving_s')
                    ->label(__('Moving'))
                    ->getStateUsing(static fn (TripRecord $record): string => number_format($record->moving_s / 60, 0).' min'),
                TextColumn::make('idle_s')
                    ->label(__('Idle'))
                    ->getStateUsing(static fn (TripRecord $record): string => number_format($record->idle_s / 60, 0).' min'),
                TextColumn::make('max_speed_kph')
                    ->label(__('Max speed'))
                    ->getStateUsing(static fn (TripRecord $record): string => number_format($record->max_speed_kph, 0).' km/h')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusOptions()[$state] ?? $state)
                    ->color(static fn (string $state): string => $state === TripStatus::Active->value ? 'success' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    // Opciones id→placa: solo los vehículos con trips, etiquetados con su placa legible.
                    ->options(static function (): array {
                        /** @var list<string> $ids */
                        $ids = TripRecord::query()
                            ->distinct()
                            ->orderBy('vehicle_id')
                            ->pluck('vehicle_id')
                            ->all();

                        $options = [];
                        foreach ($ids as $id) {
                            $options[$id] = VehiclePlates::label($id) ?? '—';
                        }

                        return $options;
                    }),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
                // Filtro por día del inicio del trip.
                Filter::make('date')
                    ->schema([
                        DatePicker::make('date')
                            ->label(__('Date'))
                            ->native(false),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<TripRecord> $query */
                        $date = $data['date'] ?? null;

                        if ($date === null || $date === '') {
                            return $query;
                        }

                        // Rango sobre timestamptz en vez de whereDate() (auditoría M-2): whereDate
                        // envuelve la columna en DATE() y anula el índice; el rango medio-abierto lo usa.
                        $day = Carbon::parse((string) $date)->startOfDay();

                        return $query
                            ->where('started_at', '>=', $day)
                            ->where('started_at', '<', $day->copy()->addDay());
                    }),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTrips::route('/'),
            'view' => ViewTrip::route('/{record}'),
        ];
    }
}
