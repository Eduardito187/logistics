<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages\CreateRefuel;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages\ListRefuels;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;

/**
 * Admin de cargas de combustible de la flota propia.
 *
 * Nota de arquitectura: RefuelRecord es proyección de lectura. El alta NO escribe el record: despacha
 * RecordRefuel al bus CQRS (ver CreateRefuel); el agregado calcula el costo total (precio × litros). La
 * placa del vehículo se resuelve en lote desde `fleet_vehicles` (cross-context, solo lectura).
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class RefuelResource extends Resource
{
    protected static ?string $model = RefuelRecord::class;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-beaker';
    }

    public static function getModelLabel(): string
    {
        return __('Refuel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Refuels');
    }

    /**
     * Opciones valor→etiqueta de los tipos de combustible (texto en inglés desde el enum).
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
     * Formulario de alta. El precio por litro se captura en bolivianos y se convierte a centavos en la
     * página de creación; el costo total lo calcula el dominio.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_id')
                ->label(__('Vehicle'))
                ->native(false)
                ->searchable()
                ->required()
                ->getSearchResultsUsing(static fn (string $search): array => VehiclePlates::search($search))
                ->getOptionLabelUsing(static fn (string $value): ?string => VehiclePlates::label($value)),
            Select::make('fuel_type')
                ->label(__('Fuel type'))
                ->options(self::fuelTypeOptions())
                ->native(false)
                ->required(),
            TextInput::make('liters')
                ->label(__('Liters'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required(),
            TextInput::make('price_per_liter_bs')
                ->label(__('Price per liter (Bs)'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required(),
            TextInput::make('currency')
                ->label(__('Currency'))
                ->default('BOB')
                ->required()
                ->maxLength(3),
            TextInput::make('odometer_km')
                ->label(__('Odometer'))
                ->numeric()
                ->minValue(0),
            TextInput::make('station')
                ->label(__('Station'))
                ->maxLength(255),
            Toggle::make('full_tank')
                ->label(__('Full tank'))
                ->default(false),
            DateTimePicker::make('refueled_at')
                ->label(__('Refueled at'))
                ->native(false)
                ->seconds(false)
                ->default(now())
                ->required(),
            TextInput::make('note')
                ->label(__('Note'))
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('refueled_at', 'desc')
            ->columns([
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (string $state): string => VehiclePlates::label($state) ?? $state)
                    ->sortable(),
                TextColumn::make('fuel_type')
                    ->label(__('Fuel type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(FuelType::from($state)->label())),
                TextColumn::make('liters')
                    ->label(__('Liters'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('total_cost_cents')
                    ->label(__('Total (Bs)'))
                    ->formatStateUsing(static fn (int $state): string => number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('odometer_km')
                    ->label(__('Odometer'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('full_tank')
                    ->label(__('Full tank'))
                    ->boolean(),
                TextColumn::make('refueled_at')
                    ->label(__('Refueled at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->options(static fn (): array => VehiclePlates::options())
                    ->native(false)
                    ->searchable(),
                SelectFilter::make('fuel_type')
                    ->label(__('Fuel type'))
                    ->options(self::fuelTypeOptions())
                    ->native(false),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRefuels::route('/'),
            'create' => CreateRefuel::route('/create'),
        ];
    }
}
