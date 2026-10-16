<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
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
    use AdminOnly;

    /**
     * Nombres de sellers memoizados por request (id => razón social), para resolver el dueño legible de
     * los mapeos de consignación en lote y evitar N+1 (patrón de enriquecimiento cross-context).
     *
     * @var array<string, string>|null
     */
    private static ?array $sellerNames = null;

    protected static ?string $model = MaterialMappingRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string | UnitEnum | null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'SAP material mappings';

    protected static ?string $modelLabel = 'material mapping';

    protected static ?string $pluralModelLabel = 'material mappings';

    protected static ?string $recordTitleAttribute = 'sku';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 34;
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
            // Dueño del material (ADR 017): vacío = mapeo propio de Dismac (retail); un seller = coordenada
            // de consignación. Select buscable con datos reales de sellers; nunca se escribe el UUID a mano.
            Select::make('seller_id')
                ->label(__('Owner'))
                ->helperText(__('Leave empty for Dismac-owned stock. Pick a seller to map a consignment coordinate.'))
                ->native(false)
                ->searchable()
                ->placeholder(__('Own (Dismac)'))
                ->getSearchResultsUsing(static fn (string $search): array => SellerRecord::query()
                    ->where('legal_name', 'like', "%{$search}%")
                    ->orderBy('legal_name')
                    ->limit(50)
                    ->pluck('legal_name', 'id')
                    ->all())
                ->getOptionLabelUsing(static fn (string $value): ?string => SellerRecord::query()
                    ->whereKey($value)
                    ->value('legal_name')),
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
            // Dueño legible (seller o Dismac); nunca el UUID.
            TextEntry::make('seller_id')
                ->label(__('Owner'))
                ->badge()
                ->state(static fn (MaterialMappingRecord $record): string => self::ownerName($record->seller_id))
                ->color(static fn (MaterialMappingRecord $record): string => $record->seller_id === null ? 'gray' : 'warning'),
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
                // Dueño legible (seller o Dismac), resuelto en lote; el UUID del seller nunca se muestra.
                TextColumn::make('seller_id')
                    ->label(__('Owner'))
                    ->badge()
                    ->state(static fn (MaterialMappingRecord $record): string => self::ownerName($record->seller_id))
                    ->color(static fn (MaterialMappingRecord $record): string => $record->seller_id === null ? 'gray' : 'warning'),
            ])
            ->defaultSort('sku')
            ->filters([
                SelectFilter::make('ownership')
                    ->label(__('Ownership'))
                    ->native(false)
                    ->options([
                        'own' => __('Own (Dismac)'),
                        'consignment' => __('Consignment'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var Builder<MaterialMappingRecord> $query */
                        return match ($data['value'] ?? null) {
                            'own' => $query->whereNull('seller_id'),
                            'consignment' => $query->whereNotNull('seller_id'),
                            default => $query,
                        };
                    }),
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
     * Dueño legible del mapeo: el seller en una coordenada de consignación, o Dismac para el mapeo propio.
     * Resuelve el nombre del seller por lectura cross-context en lote (read-only), sin acoplar contextos.
     */
    private static function ownerName(?string $sellerId): string
    {
        if ($sellerId === null) {
            return __('Own (Dismac)');
        }

        return self::sellerNames()[$sellerId] ?? __('Seller');
    }

    /**
     * Mapa id => razón social de los sellers, cargado una sola vez por request (memoizado).
     *
     * @return array<string, string>
     */
    private static function sellerNames(): array
    {
        if (self::$sellerNames === null) {
            /** @var array<string, string> $names */
            $names = SellerRecord::query()->pluck('legal_name', 'id')->all();
            self::$sellerNames = $names;
        }

        return self::$sellerNames;
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
