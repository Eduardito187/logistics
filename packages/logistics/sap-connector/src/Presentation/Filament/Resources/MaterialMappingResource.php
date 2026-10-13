<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;
use UnitEnum;

/**
 * Admin del mapa de traducción sku propio ↔ coordenada SAP (material + centro + almacén), corazón de la
 * capa anti-corrupción (ADR 002, P2-SAP-06: mapeo configurable). Es configuración CRUD completa.
 *
 * Excepción consciente de capas: Filament necesita un modelo Eloquent, así que el admin se apoya en el
 * Record de Infrastructure. El flujo de requests de usuario sigue pasando por Application vía los buses.
 * Todo texto visible va en inglés (convención del admin de la plataforma).
 */
final class MaterialMappingResource extends Resource
{
    protected static ?string $model = MaterialMappingRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string | UnitEnum | null $navigationGroup = 'Integrations';

    protected static ?string $navigationLabel = 'SAP material mappings';

    protected static ?string $modelLabel = 'material mapping';

    protected static ?string $pluralModelLabel = 'material mappings';

    protected static ?string $recordTitleAttribute = 'sku';

    public static function getNavigationGroup(): string
    {
        return __('Integrations');
    }

    public static function getNavigationLabel(): string
    {
        return __('SAP material mappings');
    }

    public static function getModelLabel(): string
    {
        return __('material mapping');
    }

    public static function getPluralModelLabel(): string
    {
        return __('material mappings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')
                ->label(__('Platform SKU'))
                ->helperText(__('The platform SKU translated to and from SAP.'))
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true),
            TextInput::make('sap_material')
                ->label(__('SAP material'))
                ->required()
                ->maxLength(64),
            TextInput::make('center')
                ->label(__('SAP center'))
                ->required()
                ->maxLength(32),
            TextInput::make('warehouse')
                ->label(__('SAP warehouse'))
                ->required()
                ->maxLength(32),
        ]);
    }

    /**
     * Vista de detalle de un mapeo de material (solo lectura).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('sku')
                ->label(__('Platform SKU'))
                ->copyable(),
            TextEntry::make('sap_material')
                ->label(__('SAP material'))
                ->copyable(),
            TextEntry::make('center')
                ->label(__('SAP center')),
            TextEntry::make('warehouse')
                ->label(__('SAP warehouse')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label(__('Platform SKU'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sap_material')
                    ->label(__('SAP material'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('center')
                    ->label(__('Center'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('warehouse')
                    ->label(__('Warehouse'))
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('sku')
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
     * @return array<string, class-string<Page>|PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialMappings::route('/'),
            'create' => Pages\CreateMaterialMapping::route('/create'),
            'view' => Pages\ViewMaterialMapping::route('/{record}'),
            'edit' => Pages\EditMaterialMapping::route('/{record}/edit'),
        ];
    }
}
