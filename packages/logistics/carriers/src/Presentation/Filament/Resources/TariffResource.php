<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\TariffRecord;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\CreateTariff;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\ListTariffs;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\ViewTariff;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de tarifas por corredor de los transportistas.
 *
 * Nota de arquitectura: igual que CarrierResource, Filament lista sobre el record Eloquent (proyección
 * de lectura, TariffRecord) y la creación despacha el comando SetTariff al bus CQRS (ver CreateTariff).
 * No hay edición genérica: una tarifa nueva se define con otro SetTariff.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class TariffResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = TariffRecord::class;

    /**
     * Tipos de corredor válidos (mismos valores que CorridorRef del dominio, replicados como texto).
     *
     * @var array<string, string>
     */
    private const CORRIDOR_TYPES = [
        'cd_to_customer' => 'CD to customer',
        'cd_to_cd' => 'CD to CD',
        'cd_to_store' => 'CD to store',
        'store_to_store' => 'Store to store',
        'store_to_cd' => 'Store to CD',
        'store_pickup' => 'Store pickup',
    ];

    /**
     * Clases de vehículo válidas (mismos valores que VehicleClass del dominio).
     *
     * @var array<string, string>
     */
    private const VEHICLE_CLASSES = [
        'trailer' => 'Trailer',
        'truck' => 'Truck',
        'third_party' => 'Third party',
    ];

    /**
     * Opciones de tipo de corredor con el label traducido (los VALUES siguen crudos).
     *
     * @return array<string, string>
     */
    private static function corridorTypeOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::CORRIDOR_TYPES);
    }

    /**
     * Opciones de clase de vehículo con el label traducido (los VALUES siguen crudos).
     *
     * @return array<string, string>
     */
    private static function vehicleClassOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::VEHICLE_CLASSES);
    }

    /**
     * Título humano del registro (para breadcrumbs y búsqueda global): transportista + corredor,
     * nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof TariffRecord) {
            return null;
        }

        $carrier = $record->carrier->name ?? '—';

        return "{$carrier} · {$record->corridor_from} → {$record->corridor_to}";
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 22;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public static function getModelLabel(): string
    {
        return __('Tariff');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Tariffs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Select buscable con datos reales de transportistas vía la relación Eloquent (regla 5).
            Select::make('carrier_id')
                ->label(__('Carrier'))
                ->relationship('carrier', 'name')
                ->required()
                ->native(false)
                ->searchable()
                ->preload(),
            TextInput::make('corridor_from')
                ->label(__('Corridor origin'))
                ->required()
                ->maxLength(255),
            TextInput::make('corridor_to')
                ->label(__('Corridor destination'))
                ->required()
                ->maxLength(255),
            Select::make('corridor_type')
                ->label(__('Corridor type'))
                ->required()
                ->native(false)
                ->options(self::corridorTypeOptions()),
            Select::make('vehicle_class')
                ->label(__('Vehicle class'))
                ->required()
                ->native(false)
                ->options(self::vehicleClassOptions()),
            TextInput::make('price_cents')
                ->label(__('Price (cents)'))
                ->helperText(__('Amount in cents to avoid rounding errors.'))
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('currency')
                ->label(__('Currency'))
                ->helperText(__('ISO currency code, e.g. BOB or USD.'))
                ->required()
                ->maxLength(3),
            DatePicker::make('effective_from')
                ->label(__('Effective from'))
                ->native(false)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nombre real del transportista vía la relación Eloquent (sin N+1 gracias a getEloquentQuery).
                TextColumn::make('carrier.name')
                    ->label(__('Carrier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('corridor_from')
                    ->label(__('Origin')),
                TextColumn::make('corridor_to')
                    ->label(__('Destination')),
                TextColumn::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->formatStateUsing(static fn (string $state): string => __(self::CORRIDOR_TYPES[$state] ?? $state)),
                TextColumn::make('vehicle_class')
                    ->label(__('Vehicle class'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(self::VEHICLE_CLASSES[$state] ?? $state)),
                TextColumn::make('price_cents')
                    ->label(__('Price'))
                    ->formatStateUsing(static fn (int $state, TariffRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                TextColumn::make('effective_from')
                    ->label(__('Effective from'))
                    ->date('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->options(self::corridorTypeOptions())
                    ->native(false),
                SelectFilter::make('vehicle_class')
                    ->label(__('Vehicle class'))
                    ->options(self::vehicleClassOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Vista de detalle de una tarifa (solo lectura): resuelve el nombre real del transportista y
     * muestra el precio en formato legible además de los centavos crudos.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Carrier'))
                ->schema([
                    TextEntry::make('carrier.name')
                        ->label(__('Carrier')),
                ]),
            Section::make(__('Corridor'))
                ->schema([
                    TextEntry::make('corridor_from')
                        ->label(__('Origin')),
                    TextEntry::make('corridor_to')
                        ->label(__('Destination')),
                    TextEntry::make('corridor_type')
                        ->label(__('Corridor type'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::CORRIDOR_TYPES[$state] ?? $state)),
                    TextEntry::make('vehicle_class')
                        ->label(__('Vehicle class'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::VEHICLE_CLASSES[$state] ?? $state)),
                ])
                ->columns(2),
            Section::make(__('Pricing'))
                ->schema([
                    TextEntry::make('price_cents')
                        ->label(__('Price'))
                        ->formatStateUsing(static fn (int $state, TariffRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('price_cents')
                        ->label(__('Price (cents)'))
                        ->numeric(),
                    TextEntry::make('currency')
                        ->label(__('Currency')),
                    TextEntry::make('effective_from')
                        ->label(__('Effective from'))
                        ->date('Y-m-d'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Eager-load del transportista para que la columna `carrier.name` no dispare una consulta por fila.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('carrier');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTariffs::route('/'),
            'create' => CreateTariff::route('/create'),
            'view' => ViewTariff::route('/{record}'),
        ];
    }
}
