<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDeparture;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\DepartureRecord;
use Logistics\FleetOps\Presentation\Filament\Concerns\ResolvesVehiclePlate;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\CreateDeparture;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\ListDepartures;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages\ViewDeparture;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de salidas/despachos de la flota propia: qué vehículo salió, con qué carga y odómetro, y cuándo
 * retornó.
 *
 * Nota de arquitectura: Filament se apoya en DepartureRecord como proyección de lectura. El alta despacha
 * RegisterDeparture al bus CQRS (ver CreateDeparture) y el cierre despacha CloseDeparture; nunca se
 * escribe el record directamente. La placa del vehículo se resuelve por lectura cruzada a `fleet_vehicles`
 * (ver ResolvesVehiclePlate), solo para mostrar.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class DepartureResource extends Resource
{
    use ResolvesVehiclePlate;

    protected static ?string $model = DepartureRecord::class;

    protected static ?string $recordTitleAttribute = 'destination';

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string
    {
        return __('Fleet Ops');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-right-start-on-rectangle';
    }

    public static function getModelLabel(): string
    {
        return __('Departure');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Departures');
    }

    /**
     * Opciones valor→etiqueta de los estados de salida (texto en inglés desde el propio enum).
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];
        foreach (DepartureStatus::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RegisterDeparture; el detalle de la
     * carga (descripción, peso e ítems) se arma en la página de creación.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Departure'))
                ->schema([
                    Select::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                        ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
                    TextInput::make('destination')
                        ->label(__('Destination'))
                        ->maxLength(255),
                    TextInput::make('route_ref')
                        ->label(__('Route reference'))
                        ->maxLength(255),
                    TextInput::make('odometer_out_km')
                        ->label(__('Odometer out (km)'))
                        ->numeric()
                        ->minValue(0),
                    DateTimePicker::make('departed_at')
                        ->label(__('Departed at'))
                        ->native(false)
                        ->seconds(false)
                        ->default(now())
                        ->required(),
                ])
                ->columns(2),
            Section::make(__('Load'))
                ->schema([
                    TextInput::make('load_description')
                        ->label(__('Load'))
                        ->maxLength(255),
                    TextInput::make('load_weight_kg')
                        ->label(__('Load weight'))
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01),
                    Repeater::make('load_items')
                        ->label(__('Items'))
                        ->schema([
                            TextInput::make('name')
                                ->label(__('Name'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('qty')
                                ->label(__('Quantity'))
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                        ])
                        ->columns(2)
                        ->default([])
                        ->addActionLabel(__('Add item'))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Textarea::make('note')
                ->label(__('Note'))
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Vista de detalle (solo lectura) de una salida: vehículo, carga, odómetros y fechas.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Departure'))
                ->schema([
                    TextEntry::make('vehicle_id')
                        ->label(__('Vehicle'))
                        ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                    TextEntry::make('destination')
                        ->label(__('Destination'))
                        ->placeholder('—'),
                    TextEntry::make('route_ref')
                        ->label(__('Route reference'))
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => $state === DepartureStatus::Out->value ? 'warning' : 'success')
                        ->formatStateUsing(static fn (string $state): string => __(DepartureStatus::from($state)->label())),
                    TextEntry::make('odometer_out_km')
                        ->label(__('Odometer out (km)'))
                        ->numeric()
                        ->placeholder('—'),
                    TextEntry::make('odometer_in_km')
                        ->label(__('Odometer in (km)'))
                        ->numeric()
                        ->placeholder('—'),
                    TextEntry::make('departed_at')
                        ->label(__('Departed at'))
                        ->dateTime(),
                    TextEntry::make('returned_at')
                        ->label(__('Returned at'))
                        ->dateTime()
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make(__('Load'))
                ->schema([
                    TextEntry::make('load_description')
                        ->label(__('Load'))
                        ->placeholder('—'),
                    TextEntry::make('load_weight_kg')
                        ->label(__('Load weight'))
                        ->placeholder('—'),
                    RepeatableEntry::make('load_items')
                        ->label(__('Items'))
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('Name')),
                            TextEntry::make('qty')
                                ->label(__('Quantity')),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('departed_at', 'desc')
            ->columns([
                // Placa legible del vehículo por lectura cruzada a fleet, nunca el UUID.
                TextColumn::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->formatStateUsing(static fn (?string $state): string => self::vehiclePlate($state)),
                TextColumn::make('destination')
                    ->label(__('Destination'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('load_weight_kg')
                    ->label(__('Load weight'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('odometer_out_km')
                    ->label(__('Odometer out (km)'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('odometer_in_km')
                    ->label(__('Odometer in (km)'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === DepartureStatus::Out->value ? 'warning' : 'success')
                    ->formatStateUsing(static fn (string $state): string => __(DepartureStatus::from($state)->label())),
                TextColumn::make('departed_at')
                    ->label(__('Departed at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('returned_at')
                    ->label(__('Returned at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('Vehicle'))
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => self::searchVehiclePlates($search))
                    ->getOptionLabelUsing(static fn (string $value): string => self::vehiclePlate($value)),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Cerrar la salida: despacha CloseDeparture al bus. Solo visible si sigue en ruta ('out').
                Action::make('close')
                    ->label(__('Close'))
                    ->icon('heroicon-o-arrow-left-end-on-rectangle')
                    ->modalHeading(__('Close departure'))
                    ->modalSubmitActionLabel(__('Close departure'))
                    ->visible(static fn (DepartureRecord $record): bool => $record->status === DepartureStatus::Out->value)
                    ->schema([
                        TextInput::make('odometer_in_km')
                            ->label(__('Odometer in (km)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        DateTimePicker::make('returned_at')
                            ->label(__('Returned at'))
                            ->native(false)
                            ->seconds(false)
                            ->default(now())
                            ->required(),
                    ])
                    ->action(static function (DepartureRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new CloseDeparture(
                            departureId: (string) $record->getKey(),
                            odometerInKm: (int) $data['odometer_in_km'],
                            returnedAt: Carbon::parse((string) $data['returned_at'])->toIso8601String(),
                        ));

                        Notification::make()
                            ->title(__('Departure closed'))
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
            'index' => ListDepartures::route('/'),
            'create' => CreateDeparture::route('/create'),
            'view' => ViewDeparture::route('/{record}'),
        ];
    }
}
