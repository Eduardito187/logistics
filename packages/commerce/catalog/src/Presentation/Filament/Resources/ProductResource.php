<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources;

use Commerce\Catalog\Application\Command\AddProductVariant\AddProductVariant;
use Commerce\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProduct;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages\CreateProduct;
use Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages\ListProducts;
use Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages\ViewProduct;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de productos del catálogo (PIM de comercio).
 *
 * Nota de arquitectura: Filament usa ProductRecord solo como proyección de lectura. El alta y toda
 * mutación (publicar, archivar, agregar variante) se hacen despachando comandos al bus CQRS, nunca
 * escribiendo el record directamente.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6). El id (UUID) nunca
 * se muestra: la categoría se resuelve a su nombre legible.
 */
final class ProductResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = ProductRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 20;
    }

    public static function getModelLabel(): string
    {
        return __('Product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Products');
    }

    /**
     * Opciones reales de categorías (id => nombre) para el select, memoizadas por request.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;

        if ($cache === null) {
            /** @var array<string, string> $cache */
            $cache = CategoryRecord::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        }

        return $cache;
    }

    /**
     * Color del badge según el estado del producto.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'published' => 'success',
            'archived' => 'gray',
            'draft' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando DraftProduct; el producto nace en borrador
     * y luego se publica con la acción correspondiente.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('canonical_sku')
                ->label(__('Canonical SKU'))
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('Description'))
                ->rows(3)
                ->maxLength(2000),
            Select::make('category_id')
                ->label(__('Category'))
                ->native(false)
                ->searchable()
                ->preload()
                ->placeholder(__('Uncategorized'))
                ->options(static fn (): array => self::categoryOptions()),
        ]);
    }

    /**
     * Vista de detalle (solo lectura). Resuelve la categoría a su nombre legible.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Product'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('Name')),
                    TextEntry::make('canonical_sku')
                        ->label(__('Canonical SKU'))
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                    TextEntry::make('category_id')
                        ->label(__('Category'))
                        ->formatStateUsing(static fn (?string $state): string => $state !== null
                            ? (self::categoryOptions()[$state] ?? __('Unknown'))
                            : __('Uncategorized'))
                        ->placeholder(__('Uncategorized')),
                    TextEntry::make('description')
                        ->label(__('Description'))
                        ->placeholder(__('No description'))
                        ->columnSpanFull(),
                    TextEntry::make('variants')
                        ->label(__('Variants'))
                        ->state(static fn (ProductRecord $record): int => count($record->variants))
                        ->formatStateUsing(static fn (int $state): string => (string) $state),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: se identifica el producto por su nombre y SKU.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('canonical_sku')
                    ->label(__('Canonical SKU'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),
                // La categoría es una FK UUID: se resuelve a su nombre legible (regla "NO UUID en el admin").
                TextColumn::make('category_id')
                    ->label(__('Category'))
                    ->formatStateUsing(static fn (?string $state): string => $state !== null
                        ? (self::categoryOptions()[$state] ?? __('Unknown'))
                        : __('Uncategorized'))
                    ->placeholder(__('Uncategorized')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options([
                        'draft' => __('Draft'),
                        'published' => __('Published'),
                        'archived' => __('Archived'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                // Publicar: despacha PublishProduct al bus. Visible solo si aún no está publicado.
                Action::make('publish')
                    ->label(__('Publish'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Publish product'))
                    ->modalDescription(__('The product will become visible and purchasable.'))
                    ->visible(static fn (ProductRecord $record): bool => $record->status !== 'published')
                    ->action(static function (ProductRecord $record): void {
                        app(CommandBus::class)->dispatch(new PublishProduct(
                            productId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Product published'))
                            ->success()
                            ->send();
                    }),
                // Archivar: despacha ArchiveProduct al bus. Visible solo si aún no está archivado.
                Action::make('archive')
                    ->label(__('Archive'))
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Archive product'))
                    ->modalDescription(__('The product will be removed from sale but its history is kept.'))
                    ->visible(static fn (ProductRecord $record): bool => $record->status !== 'archived')
                    ->action(static function (ProductRecord $record): void {
                        app(CommandBus::class)->dispatch(new ArchiveProduct(
                            productId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Product archived'))
                            ->success()
                            ->send();
                    }),
                // Agregar variante: despacha AddProductVariant al bus (SKU + atributos clave/valor).
                Action::make('addVariant')
                    ->label(__('Add variant'))
                    ->icon('heroicon-o-squares-plus')
                    ->modalHeading(__('Add product variant'))
                    ->modalSubmitActionLabel(__('Add variant'))
                    ->schema([
                        TextInput::make('sku')
                            ->label(__('SKU'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        KeyValue::make('attributes')
                            ->label(__('Attributes'))
                            ->keyLabel(__('Attribute'))
                            ->valueLabel(__('Value')),
                    ])
                    ->action(static function (ProductRecord $record, array $data): void {
                        /** @var array<string, string> $attributes */
                        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];

                        app(CommandBus::class)->dispatch(new AddProductVariant(
                            productId: (string) $record->getKey(),
                            sku: (string) $data['sku'],
                            name: (string) $data['name'],
                            attributes: $attributes,
                        ));

                        Notification::make()
                            ->title(__('Variant added'))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }
}
