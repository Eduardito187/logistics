<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages\ListStockOnHand;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages\ViewStockLot;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Cockpit de stock on-hand por almacén, con filtro por DUEÑO (propio de Dismac vs. consignación de seller).
 *
 * Es un tablero de lectura: el stock NO se crea ni edita por CRUD desde el admin (eso lo hacen los
 * comandos de dominio / la recepción de inbound). Por eso el Resource solo expone listar y ver.
 *
 * Grupo de navegación: **Operations** (es operación de almacén). Se respeta la regla de los 5 grupos
 * fijos del admin (Operations, Fleet, Network, Commerce, Administration): NO se crea un grupo "Inventory".
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6). Sin UUID visible: el id del lote,
 * el almacén y el dueño se muestran por su nombre legible (resuelto en lote, read-only, cross-context).
 */
final class StockOnHandResource extends Resource
{
    use OfficeOnly;

    /** @var array<string, string>|null Caché por request de id de almacén → nombre (network_nodes). */
    private static ?array $warehouseNames = null;

    /** @var array<string, string>|null Caché por request de id de seller → nombre legal (sellers). */
    private static ?array $sellerNames = null;

    protected static ?string $model = InventoryLotRecord::class;

    protected static ?string $recordTitleAttribute = 'sku';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationSort(): int
    {
        return 60;
    }

    public static function getModelLabel(): string
    {
        return __('Stock lot');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock on hand');
    }

    /**
     * Vista de detalle (solo lectura) de un lote.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Stock lot'))
                ->schema([
                    TextEntry::make('sku')->label(__('SKU')),
                    TextEntry::make('warehouse_id')
                        ->label(__('Warehouse'))
                        ->formatStateUsing(static fn (string $state): string => self::warehouseLabel($state)),
                    TextEntry::make('fulfillment_mode')
                        ->label(__('Fulfillment mode'))
                        ->badge()
                        ->color(static fn (string $state): string => self::modeColor($state))
                        ->formatStateUsing(static fn (string $state): string => self::modeLabel($state)),
                    TextEntry::make('owner_party_id')
                        ->label(__('Owner'))
                        ->formatStateUsing(static fn (?string $state): string => self::ownerLabel($state)),
                    TextEntry::make('seller_sku_ref')
                        ->label(__('Seller SKU'))
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make(__('Quantities'))
                ->schema([
                    TextEntry::make('available_quantity')->label(__('Available')),
                    TextEntry::make('reserved_quantity')->label(__('Reserved')),
                    TextEntry::make('on_hand')
                        ->label(__('On hand'))
                        ->state(static fn (InventoryLotRecord $record): int => $record->available_quantity + $record->reserved_quantity),
                    TextEntry::make('unit_cost_cents')
                        ->label(__('Unit cost'))
                        ->formatStateUsing(static fn (int | string $state, InventoryLotRecord $record): string => number_format(((int) $state) / 100, 2).' '.$record->currency),
                    TextEntry::make('received_at')->label(__('Received at'))->dateTime(),
                    TextEntry::make('goods_receipt_ref')->label(__('Goods receipt'))->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse_id')
                    ->label(__('Warehouse'))
                    ->formatStateUsing(static fn (string $state): string => self::warehouseLabel($state)),
                TextColumn::make('owner_party_id')
                    ->label(__('Owner'))
                    ->formatStateUsing(static fn (?string $state): string => self::ownerLabel($state)),
                TextColumn::make('fulfillment_mode')
                    ->label(__('Fulfillment mode'))
                    ->badge()
                    ->color(static fn (string $state): string => self::modeColor($state))
                    ->formatStateUsing(static fn (string $state): string => self::modeLabel($state)),
                TextColumn::make('available_quantity')
                    ->label(__('Available'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reserved_quantity')
                    ->label(__('Reserved'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('on_hand')
                    ->label(__('On hand'))
                    ->state(static fn (InventoryLotRecord $record): int => $record->available_quantity + $record->reserved_quantity),
                TextColumn::make('unit_cost_cents')
                    ->label(__('Unit cost'))
                    ->formatStateUsing(static fn (int | string $state, InventoryLotRecord $record): string => number_format(((int) $state) / 100, 2).' '.$record->currency)
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->label(__('Received at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                // Filtro por dueño: propio de Dismac vs. consignación de seller.
                SelectFilter::make('owner')
                    ->label(__('Owner'))
                    ->options([
                        'owned' => __('Dismac (owned)'),
                        'seller' => __('Seller-owned'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<InventoryLotRecord> $query */
                        $value = $data['value'] ?? null;
                        if ($value === 'owned') {
                            return $query->whereNull('owner_party_id');
                        }
                        if ($value === 'seller') {
                            return $query->whereNotNull('owner_party_id');
                        }

                        return $query;
                    })
                    ->native(false),
                // Filtro detallado por modo de fulfillment.
                SelectFilter::make('fulfillment_mode')
                    ->label(__('Fulfillment mode'))
                    ->options([
                        'owned' => __('Owned (retail)'),
                        'fbd' => __('Fulfilled by Dismac'),
                        'dropship' => __('Dropship'),
                    ])
                    ->native(false),
            ])
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
            'index' => ListStockOnHand::route('/'),
            'view' => ViewStockLot::route('/{record}'),
        ];
    }

    /**
     * Nombre legible del almacén (node de network), resuelto en lote y cacheado por request para evitar
     * N+1. Degrada a un id corto si la tabla no existe o el nodo no está.
     */
    private static function warehouseLabel(string $id): string
    {
        if (self::$warehouseNames === null) {
            self::$warehouseNames = self::loadNames('network_nodes', 'name');
        }

        return self::$warehouseNames[$id] ?? ('#'.substr($id, 0, 8));
    }

    /**
     * Nombre legible del dueño: "Dismac (owned)" si es propio, o el nombre legal del seller si es
     * consignación. Resuelto en lote y cacheado por request.
     */
    private static function ownerLabel(?string $ownerPartyId): string
    {
        if ($ownerPartyId === null || $ownerPartyId === '') {
            return __('Dismac (owned)');
        }
        if (self::$sellerNames === null) {
            self::$sellerNames = self::loadNames('sellers', 'legal_name');
        }

        return self::$sellerNames[$ownerPartyId] ?? ('#'.substr($ownerPartyId, 0, 8));
    }

    /**
     * Carga read-only un mapa id → etiqueta de otra tabla (cross-context, sin acople composer). Degrada a
     * mapa vacío si la tabla no existe (el contexto se mantiene autónomo).
     *
     * @return array<string, string>
     */
    private static function loadNames(string $table, string $labelColumn): array
    {
        $map = [];
        try {
            foreach (DB::table($table)->get(['id', $labelColumn]) as $row) {
                $map[(string) $row->id] = (string) $row->{$labelColumn};
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    private static function modeColor(string $mode): string
    {
        return match ($mode) {
            'owned' => 'gray',
            'fbd' => 'success',
            'dropship' => 'info',
            default => 'gray',
        };
    }

    private static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'owned' => __('Owned (retail)'),
            'fbd' => __('Fulfilled by Dismac'),
            'dropship' => __('Dropship'),
            default => $mode,
        };
    }
}
