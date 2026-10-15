<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\CreateShipmentLine;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\EditShipmentLine;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\ListShipmentLines;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\ViewShipmentLine;

/**
 * Admin de líneas de pedido: cada SKU con su cantidad dentro de un envío.
 *
 * Filament opera sobre el record Eloquent (ShipmentLineRecord) por diseño del framework. El select
 * de pedido es buscable y usa la relación real (external_id). Todo texto visible va en inglés
 * (regla 5 del proyecto).
 *
 * @extends resource<ShipmentLineRecord>
 */
final class ShipmentLineResource extends Resource
{
    protected static ?string $model = ShipmentLineRecord::class;

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'sku';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-list-bullet';
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getModelLabel(): string
    {
        return __('Order line');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Order lines');
    }

    /**
     * Carga anticipada del pedido relacionado: la tabla y los selects muestran la referencia humana
     * (shipment.external_id) sin disparar una consulta por fila (evita N+1).
     *
     * @return Builder<ShipmentLineRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('shipment');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('shipment_id')
                ->label(__('Order'))
                ->relationship('shipment', 'external_id')
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),
            TextInput::make('sku')
                ->label(__('SKU'))
                ->required()
                ->maxLength(64),
            TextInput::make('product_name')
                ->label(__('Product name'))
                ->required()
                ->maxLength(255),
            TextInput::make('product_image_url')
                ->label(__('Product image URL'))
                ->url()
                ->maxLength(2048)
                ->helperText(__('Optional. Shown as a thumbnail in customer notifications.')),
            TextInput::make('unit_price')
                ->label(__('Unit price'))
                ->numeric()
                ->minValue(0)
                ->step('0.01'),
            TextInput::make('unit_price_currency')
                ->label(__('Currency'))
                ->maxLength(3)
                ->default('BOB')
                ->helperText(__('ISO 4217 code, e.g. BOB.')),
            TextInput::make('quantity')
                ->label(__('Quantity'))
                ->required()
                ->numeric()
                ->minValue(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('product_image_url')
                    ->label(__('Image'))
                    ->square()
                    ->imageSize(40)
                    ->checkFileExistence(false)
                    // Sin URL → placeholder; URL externa que responde 404 → onerror la cambia por el
                    // placeholder. Nunca una celda en blanco ni una imagen rota.
                    ->defaultImageUrl(ImageFallback::placeholder('box'))
                    ->extraImgAttributes(ImageFallback::onError('box'))
                    ->toggleable(),
                TextColumn::make('shipment.external_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label(__('Product name'))
                    ->searchable()
                    ->limit(50),
                TextColumn::make('unit_price')
                    ->label(__('Unit price'))
                    ->money(fn (ShipmentLineRecord $record): string => $record->unit_price_currency ?? 'BOB')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shipment_id')
                    ->label(__('Order'))
                    ->relationship('shipment', 'external_id')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sku');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListShipmentLines::route('/'),
            'create' => CreateShipmentLine::route('/create'),
            'view' => ViewShipmentLine::route('/{record}'),
            'edit' => EditShipmentLine::route('/{record}/edit'),
        ];
    }
}
