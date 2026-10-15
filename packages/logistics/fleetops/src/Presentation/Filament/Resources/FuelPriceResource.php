<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\FleetOps\Application\Command\ActivateFuelPrice\ActivateFuelPrice;
use Logistics\FleetOps\Application\Command\DeactivateFuelPrice\DeactivateFuelPrice;
use Logistics\FleetOps\Application\Command\DeleteFuelPrice\DeleteFuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\CreateFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\EditFuelPrice;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages\ListFuelPrices;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de precios de combustible por tipo y fecha de vigencia.
 *
 * Nota de arquitectura: Filament se apoya en FuelPriceRecord como proyección de lectura. Toda MUTACIÓN
 * (alta, edición, activar/desactivar, eliminar) NO escribe el record directo: despacha comandos al bus
 * CQRS (SetFuelPrice, UpdateFuelPrice, ActivateFuelPrice, DeactivateFuelPrice, DeleteFuelPrice).
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class FuelPriceResource extends Resource
{
    protected static ?string $model = FuelPriceRecord::class;

    protected static ?string $recordTitleAttribute = 'fuel_type';

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 34;
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
            Select::make('currency')
                ->label(__('Currency'))
                ->options([
                    'BOB' => __('BOB'),
                    'USD' => __('USD'),
                ])
                ->native(false)
                ->default('BOB')
                ->required(),
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
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('fuel_type')
                    ->label(__('Fuel type'))
                    ->options(self::fuelTypeOptions())
                    ->native(false),
                SelectFilter::make('active')
                    ->label(__('Active'))
                    ->options([
                        '1' => __('Active'),
                        '0' => __('Inactive'),
                    ])
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make(),
                // Activar: despacha ActivateFuelPrice al bus. Solo visible si está inactivo.
                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Activate fuel price'))
                    ->visible(static fn (FuelPriceRecord $record): bool => !(bool) $record->active)
                    ->action(static function (FuelPriceRecord $record): void {
                        app(CommandBus::class)->dispatch(new ActivateFuelPrice(
                            id: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Fuel price activated'))
                            ->success()
                            ->send();
                    }),
                // Desactivar: despacha DeactivateFuelPrice al bus. Solo visible si está activo.
                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Deactivate fuel price'))
                    ->visible(static fn (FuelPriceRecord $record): bool => (bool) $record->active)
                    ->action(static function (FuelPriceRecord $record): void {
                        app(CommandBus::class)->dispatch(new DeactivateFuelPrice(
                            id: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Fuel price deactivated'))
                            ->success()
                            ->send();
                    }),
                // Eliminar: despacha DeleteFuelPrice al bus (no escribe el record directo).
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Delete fuel price'))
                    ->modalDescription(__('This fuel price will be permanently removed.'))
                    ->action(static function (FuelPriceRecord $record): void {
                        app(CommandBus::class)->dispatch(new DeleteFuelPrice(
                            id: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Fuel price deleted'))
                            ->success()
                            ->send();
                    }),
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
            'edit' => EditFuelPrice::route('/{record}/edit'),
        ];
    }
}
