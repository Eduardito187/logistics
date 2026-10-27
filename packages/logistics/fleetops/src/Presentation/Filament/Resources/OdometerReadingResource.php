<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\OdometerReadingRecord;
use Logistics\FleetOps\Presentation\Filament\Concerns\ResolvesVehiclePlate;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\CreateOdometerReading;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\ListOdometerReadings;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages\ViewOdometerReading;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de lecturas de odómetro por vehículo y fuente.
 *
 * Nota de arquitectura: Filament se apoya en OdometerReadingRecord como proyección de lectura. El alta NO
 * escribe el record: despacha RecordOdometer al bus CQRS (ver CreateOdometerReading). La placa del vehículo
 * se resuelve por lectura cruzada a `fleet_vehicles` (ver ResolvesVehiclePlate), solo para mostrar.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class OdometerReadingResource extends Resource
{
    use OfficeOnly;
    use ResolvesVehiclePlate;

    protected static ?string $model = OdometerReadingRecord::class;

    protected static ?string $recordTitleAttribute = 'km';

    protected static ?int $navigationSort = 36;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationIcon(): string
    {
        // Odómetro: ícono propio del set DisTrack (Heroicons no tiene un medidor).
        return 'ds-gauge';
    }

    public static function getModelLabel(): string
    {
        return __('Odometer reading');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Odometer readings');
    }

    /**
     * Opciones valor→etiqueta de las fuentes de lectura (texto en inglés desde el propio enum).
     *
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        $options = [];
        foreach (OdometerSource::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RecordOdometer.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->required()
                ->native(false)
                ->searchable()
                ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
            TextInput::make('km')
                ->label(__('Odometer'))
                ->numeric()
                ->minValue(0)
                ->required(),
            Select::make('source')
                ->label(__('Source'))
                ->options(self::sourceOptions())
                ->native(false)
                ->default(OdometerSource::Manual->value)
                ->required(),
            DateTimePicker::make('read_at')
                ->label(__('Read at'))
                ->native(false)
                ->seconds(false)
                ->default(now())
                ->required(),
            Textarea::make('note')
                ->label(__('Note'))
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) de una lectura de odómetro.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Odometer reading'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                    TextEntry::make('km')
                        ->label(__('Odometer'))
                        ->numeric(),
                    TextEntry::make('source')
                        ->label(__('Source'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(OdometerSource::from($state)->label())),
                    TextEntry::make('read_at')
                        ->label(__('Read at'))
                        ->dateTime(),
                    TextEntry::make('note')
                        ->label(__('Note'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('read_at', 'desc')
            ->columns([
                // Placa legible del vehículo por lectura cruzada a fleet, nunca el UUID.
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                TextColumn::make('km')
                    ->label(__('Odometer'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(OdometerSource::from($state)->label())),
                TextColumn::make('read_at')
                    ->label(__('Read at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                    ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
                SelectFilter::make('source')
                    ->label(__('Source'))
                    ->options(self::sourceOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOdometerReadings::route('/'),
            'create' => CreateOdometerReading::route('/create'),
            'view' => ViewOdometerReading::route('/{record}'),
        ];
    }
}
