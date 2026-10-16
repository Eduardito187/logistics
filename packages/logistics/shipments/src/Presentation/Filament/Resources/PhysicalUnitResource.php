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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\CreatePhysicalUnit;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\EditPhysicalUnit;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\ListPhysicalUnits;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\ViewPhysicalUnit;

/**
 * Admin de bultos (unidades físicas): un bulto por unidad de producto, con su "N de M" de unidad y
 * de pieza. Es la unidad que se escanea en preparación, carga y entrega.
 *
 * Filament opera sobre el record Eloquent (PhysicalUnitRecord). Los selects de pedido y línea son
 * buscables y usan las relaciones reales. Todo texto visible va en inglés (regla 5 del proyecto).
 *
 * @extends resource<PhysicalUnitRecord>
 */
final class PhysicalUnitResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = PhysicalUnitRecord::class;

    protected static ?int $navigationSort = 14;

    /**
     * Título humano del bulto: referencia del pedido + posición "unidad/total" (nunca el UUID crudo).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof PhysicalUnitRecord) {
            return null;
        }

        $reference = $record->shipment->external_id ?? __('Order');

        return "{$reference} — ".__('unit')." {$record->unit_index}/{$record->unit_total}";
    }

    /**
     * Carga anticipada del pedido y la línea: la tabla muestra referencia y SKU humanos sin N+1.
     *
     * @return Builder<PhysicalUnitRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['shipment', 'line']);
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cube';
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getModelLabel(): string
    {
        return __('Package');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Packages');
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
            Select::make('line_id')
                ->label(__('Order line'))
                ->relationship('line', 'sku')
                ->getOptionLabelFromRecordUsing(static fn (ShipmentLineRecord $record): string => "{$record->sku} — {$record->product_name}")
                ->searchable()
                ->preload()
                ->native(false)
                ->required(),
            TextInput::make('unit_index')
                ->label(__('Unit index'))
                ->required()
                ->numeric()
                ->minValue(1),
            TextInput::make('unit_total')
                ->label(__('Units total'))
                ->required()
                ->numeric()
                ->minValue(1),
            TextInput::make('piece_index')
                ->label(__('Piece index'))
                ->required()
                ->numeric()
                ->minValue(1),
            TextInput::make('piece_total')
                ->label(__('Pieces total'))
                ->required()
                ->numeric()
                ->minValue(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment.external_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('line.sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('line.product_name')
                    ->label(__('Product'))
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('unit_index')
                    ->label(__('Unit'))
                    ->formatStateUsing(static fn (int $state, PhysicalUnitRecord $record): string => "{$state}/{$record->unit_total}"),
                TextColumn::make('piece_index')
                    ->label(__('Piece'))
                    ->formatStateUsing(static fn (int $state, PhysicalUnitRecord $record): string => "{$state}/{$record->piece_total}"),
                TextColumn::make('id')
                    ->label(__('Package ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPhysicalUnits::route('/'),
            'create' => CreatePhysicalUnit::route('/create'),
            'view' => ViewPhysicalUnit::route('/{record}'),
            'edit' => EditPhysicalUnit::route('/{record}/edit'),
        ];
    }
}
