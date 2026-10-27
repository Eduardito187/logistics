<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;
use Logistics\FleetOps\Presentation\Filament\Concerns\ResolvesVehiclePlate;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\CreateVehicleProfile;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\EditVehicleProfile;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\ListVehicleProfiles;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages\ViewVehicleProfile;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin del perfil técnico de cada vehículo (relación 1:1 con el vehículo de fleet, PK `vehicle_id`).
 *
 * Nota de arquitectura: Filament se apoya en VehicleProfileRecord como proyección de lectura. Tanto el
 * alta como la edición despachan DefineVehicleProfile al bus CQRS (es un UPSERT por `vehicle_id`): nunca
 * se escribe el record directamente. El odómetro vigente no se edita aquí (lo lleva UpdateVehicleOdometer).
 * La placa del vehículo se resuelve por lectura cruzada a `fleet_vehicles` (ver ResolvesVehiclePlate).
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class VehicleProfileResource extends Resource
{
    use OfficeOnly;
    use ResolvesVehiclePlate;

    protected static ?string $model = VehicleProfileRecord::class;

    protected static ?int $navigationSort = 38;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationIcon(): string
    {
        return 'ds-identification';
    }

    public static function getModelLabel(): string
    {
        return __('Vehicle profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vehicle profiles');
    }

    /**
     * Título legible del registro: la placa del vehículo, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof VehicleProfileRecord) {
            return null;
        }

        return self::vehiclePlate((string) $record->getKey());
    }

    /**
     * Opciones valor→etiqueta de los tipos de combustible (texto en inglés desde el propio enum).
     *
     * @return array<string, string>
     */
    public static function fuelTypeOptions(): array
    {
        $options = [];
        foreach (FuelType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta/edición. Los campos que admite DefineVehicleProfile. En edición el vehículo es
     * fijo (es la PK del perfil); el id real se toma del record en la página, no del formulario.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vehicle profile'))
                ->schema([
                    Select::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->disabled(static fn (string $operation): bool => $operation === 'edit')
                        ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                        ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
                    Select::make('fuel_type')
                        ->label(__('Fuel type'))
                        ->options(self::fuelTypeOptions())
                        ->native(false),
                    TextInput::make('brand')
                        ->label(__('Brand'))
                        ->maxLength(255),
                    TextInput::make('model')
                        ->label(__('Model'))
                        ->maxLength(255),
                    TextInput::make('year')
                        ->label(__('Year'))
                        ->numeric()
                        ->minValue(1900)
                        ->maxValue(2100),
                    TextInput::make('tank_liters')
                        ->label(__('Tank'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                    TextInput::make('vin')
                        ->label(__('VIN'))
                        ->maxLength(64),
                    TextInput::make('color')
                        ->label(__('Color'))
                        ->maxLength(64),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) del perfil técnico de un vehículo.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vehicle profile'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                    TextEntry::make('fuel_type')
                        ->label(__('Fuel type'))
                        ->badge()
                        ->placeholder('—')
                        ->formatStateUsing(static fn (?string $state): string => $state === null ? '—' : __(FuelType::from($state)->label())),
                    TextEntry::make('brand')
                        ->label(__('Brand'))
                        ->placeholder('—'),
                    TextEntry::make('model')
                        ->label(__('Model'))
                        ->placeholder('—'),
                    TextEntry::make('year')
                        ->label(__('Year'))
                        ->placeholder('—'),
                    TextEntry::make('tank_liters')
                        ->label(__('Tank'))
                        ->placeholder('—'),
                    TextEntry::make('vin')
                        ->label(__('VIN'))
                        ->placeholder('—'),
                    TextEntry::make('color')
                        ->label(__('Color'))
                        ->placeholder('—'),
                    TextEntry::make('current_odometer_km')
                        ->label(__('Odometer'))
                        ->numeric()
                        ->placeholder('—'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Placa legible del vehículo por lectura cruzada a fleet, nunca el UUID.
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                TextColumn::make('brand')
                    ->label(__('Brand'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('model')
                    ->label(__('Model'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('year')
                    ->label(__('Year'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('fuel_type')
                    ->label(__('Fuel type'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? '—' : __(FuelType::from($state)->label())),
                TextColumn::make('current_odometer_km')
                    ->label(__('Odometer'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
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
            'index' => ListVehicleProfiles::route('/'),
            'create' => CreateVehicleProfile::route('/create'),
            'view' => ViewVehicleProfile::route('/{record}'),
            'edit' => EditVehicleProfile::route('/{record}/edit'),
        ];
    }
}
