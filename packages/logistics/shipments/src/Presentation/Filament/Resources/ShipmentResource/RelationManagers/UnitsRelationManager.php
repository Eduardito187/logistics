<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Bultos (unidades físicas) del pedido, embebidos en la vista/edición del envío.
 *
 * Trabaja sobre la relación `units` del ShipmentRecord. El select de línea queda acotado a las
 * líneas del propio pedido. El string fuente queda en inglés dentro de __() para poder traducirse
 * (regla 5).
 */
final class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Packages');
    }

    public static function getModelLabel(): string
    {
        return __('package');
    }

    public function form(Schema $schema): Schema
    {
        /** @var ShipmentRecord $owner */
        $owner = $this->getOwnerRecord();

        return $schema->components([
            Select::make('line_id')
                ->label(__('Order line'))
                ->relationship(
                    name: 'line',
                    titleAttribute: 'sku',
                    modifyQueryUsing: static function (Builder $query) use ($owner): Builder {
                        /** @var Builder<ShipmentLineRecord> $query */
                        return $query->where('shipment_id', $owner->getKey());
                    },
                )
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

    public function table(Table $table): Table
    {
        return $table
            // Título legible del bulto (SKU + "N/M"), nunca el UUID de la pk: aparece en los modales de
            // confirmación (p. ej. borrado) y en la cabecera del registro.
            ->recordTitle(static fn (PhysicalUnitRecord $record): string => trim(
                ($record->line->sku ?? __('package')).' — '.__('unit')." {$record->unit_index}/{$record->unit_total}",
            ))
            ->columns([
                TextColumn::make('line.sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit_index')
                    ->label(__('Unit'))
                    ->formatStateUsing(static fn (int $state, PhysicalUnitRecord $record): string => "{$state}/{$record->unit_total}"),
                TextColumn::make('piece_index')
                    ->label(__('Piece'))
                    ->formatStateUsing(static fn (int $state, PhysicalUnitRecord $record): string => "{$state}/{$record->piece_total}"),
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
            ]);
    }
}
