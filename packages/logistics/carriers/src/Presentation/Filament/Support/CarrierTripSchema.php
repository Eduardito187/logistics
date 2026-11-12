<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;

/**
 * Piezas de UI del admin de viajes de transportista (estados, selector de envíos, columnas y secciones del
 * infolist), separadas del Resource para que este quede como orquestador de páginas y acciones.
 *
 * Texto visible en inglés (regla 5); nunca UUIDs visibles.
 */
final class CarrierTripSchema
{
    /**
     * Estados del ciclo de vida (mismos valores que CarrierTripStatus del dominio).
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    /** Formato de fecha y hora de las columnas e infolists del viaje. */
    public const DATETIME_FORMAT = 'Y-m-d H:i';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::STATUSES);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'scheduled' => 'warning',
            'in_progress' => 'info',
            'closed' => 'success',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Select buscable de envíos cargables (no en otro viaje vigente), por pedido o cliente.
     */
    public static function shipmentPicker(string $name): Select
    {
        return Select::make($name)
            ->label(__('Shipments'))
            ->helperText(__('Search by order number or customer. Shipments already on an active trip are excluded.'))
            ->multiple()
            ->native(false)
            ->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => TripLookups::searchLoadableShipments($search))
            ->getOptionLabelsUsing(static fn (array $values): array => TripLookups::shipmentLabels(
                array_values(array_filter($values, static fn (mixed $v): bool => \is_string($v))),
            ));
    }

    /**
     * Columnas de la tabla de viajes.
     *
     * @return list<TextColumn>
     */
    public static function tableColumns(): array
    {
        return [
            TextColumn::make('carrier.name')
                ->label(__('Carrier'))
                ->searchable()
                ->sortable(),
            TextColumn::make('corridor_from')
                ->label(__('Pickup node'))
                ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
            TextColumn::make('corridor_to')
                ->label(__('Destination node'))
                ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
            TextColumn::make('scheduled_at')
                ->label(__('Scheduled departure'))
                ->dateTime(self::DATETIME_FORMAT)
                ->sortable(),
            TextColumn::make('shipments_count')
                ->label(__('Shipments'))
                ->counts('shipments')
                ->sortable(),
            TextColumn::make('reference')
                ->label(__('Reference'))
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(static fn (string $state): string => self::statusColor($state))
                ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
        ];
    }

    /**
     * Sección del infolist con los datos del viaje (corredor por nombre, fechas y estado).
     */
    public static function tripSection(): Section
    {
        return Section::make(__('Trip'))
            ->schema([
                TextEntry::make('carrier.name')
                    ->label(__('Carrier')),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                TextEntry::make('corridor_from')
                    ->label(__('Pickup node'))
                    ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                TextEntry::make('corridor_to')
                    ->label(__('Destination node'))
                    ->formatStateUsing(static fn (?string $state): string => TripLookups::nodeName($state)),
                TextEntry::make('scheduled_at')
                    ->label(__('Scheduled departure'))
                    ->dateTime(self::DATETIME_FORMAT),
                TextEntry::make('reference')
                    ->label(__('Reference'))
                    ->placeholder('—'),
                TextEntry::make('started_at')
                    ->label(__('Started at'))
                    ->dateTime(self::DATETIME_FORMAT)
                    ->placeholder(__('Not started yet')),
                TextEntry::make('closed_at')
                    ->label(__('Closed at'))
                    ->dateTime(self::DATETIME_FORMAT)
                    ->placeholder(__('Not closed yet')),
            ])
            ->columns(2);
    }

    /**
     * Sección del infolist con la carga: un renglón por envío con productos (nombre + SKU).
     */
    public static function loadSection(): Section
    {
        return Section::make(__('Load'))
            ->schema([
                RepeatableEntry::make('load')
                    ->label(__('Shipments'))
                    ->state(static fn (CarrierTripRecord $record): array => TripLookups::load($record->id))
                    ->placeholder(__('No shipments assigned yet'))
                    ->schema([
                        TextEntry::make('shipment')
                            ->label(__('Order')),
                        TextEntry::make('destination')
                            ->label(__('Destination')),
                        TextEntry::make('units')
                            ->label(__('Products to pick up')),
                        TextEntry::make('products')
                            ->label(__('Products'))
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
