<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\CreateFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\ListFuelPrices;

/**
 * Admin de precios de combustible por tipo y fecha de vigencia.
 *
 * Nota de arquitectura: Filament se apoya en FuelPriceRecord como proyección de lectura. El alta de un
 * precio NO escribe el record: despacha el comando SetFuelPrice al bus CQRS (ver CreateFuelPrice). El
 * recurso no define edición: los precios son inmutables (se fija uno nuevo con vigencia posterior).
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class FuelPriceResource extends Resource
{
    protected static ?string $model = FuelPriceRecord::class;

    protected static ?string $recordTitleAttribute = 'fuel_type';

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-currency-dollar';
    }

    public static function getModelLabel(): string
    {
        return __('Fuel price');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fuel prices');
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
     * Formulario de alta. Solo los campos que admite el comando SetFuelPrice; el precio se captura en
     * bolivianos y se convierte a centavos en la página de creación.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fuel_type')
                ->label(__('Fuel type'))
                ->options(self::fuelTypeOptions())
                ->native(false)
                ->required(),
            TextInput::make('price_bs')
                ->label(__('Price (Bs)'))
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->required(),
            TextInput::make('currency')
                ->label(__('Currency'))
                ->default('BOB')
                ->required()
                ->maxLength(3),
            DatePicker::make('effective_from')
                ->label(__('Effective from'))
                ->native(false)
                ->default(date('Y-m-d'))
                ->required(),
            TextInput::make('source')
                ->label(__('Source'))
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('fuel_type')
                    ->label(__('Fuel type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(FuelType::from($state)->label()))
                    ->sortable(),
                TextColumn::make('price_cents')
                    ->label(__('Price (Bs)'))
                    ->formatStateUsing(static fn (int $state): string => number_format($state / 100, 2))
                    ->sortable(),
                TextColumn::make('currency')
                    ->label(__('Currency')),
                TextColumn::make('effective_from')
                    ->label(__('Effective from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->placeholder('—'),
            ])
            ->filters([
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
            'index' => ListFuelPrices::route('/'),
            'create' => CreateFuelPrice::route('/create'),
        ];
    }
}
