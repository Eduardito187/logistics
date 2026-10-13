<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;

/**
 * Líneas de producto del pedido, embebidas en la vista/edición del envío.
 *
 * Trabaja sobre la relación `lines` del ShipmentRecord. El string fuente queda en inglés dentro de
 * __() para poder traducirse (regla 5).
 */
final class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Order lines');
    }

    public static function getModelLabel(): string
    {
        return __('line');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                ImageColumn::make('product_image_url')
                    ->label(__('Image'))
                    ->square()
                    ->imageSize(40)
                    ->checkFileExistence(false)
                    ->toggleable(),
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
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
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
}
