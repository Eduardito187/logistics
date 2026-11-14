<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources;

use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\ListCategories;
use Commerce\Catalog\Presentation\Filament\Support\PhysicalSpecForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Admin de categorías del catálogo (PIM de comercio).
 *
 * Nota de arquitectura: Filament usa CategoryRecord solo como proyección de lectura. El alta se hace
 * despachando el comando CreateCategory al bus CQRS (el dominio genera el id y normaliza el slug), nunca
 * escribiendo el record directamente.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6). El id (UUID) nunca
 * se muestra: la categoría padre se resuelve a su nombre legible.
 */
final class CategoryResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = CategoryRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    // Fuera del menú: las categorías son parte del CATÁLOGO; se gestionan desde «Catálogo de productos»
    // (no como ítem CRUD suelto). El menú de Comercio queda con un solo acceso al catálogo.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 62;
    }

    public static function getModelLabel(): string
    {
        return __('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Categories');
    }

    /**
     * Opciones reales de categorías (id => nombre), ordenadas por nombre, para el select de padre.
     *
     * Se cachean en Valkey con TTL corto en vez del viejo `static $cache` de la función: bajo Octane el
     * worker vive entre requests y esa estática serviría nombres viejos indefinidamente; el caché con TTL
     * se refresca solo (60s de staleness es aceptable). Clave compartida con ProductResource (misma
     * consulta, misma forma).
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return Cache::remember(
            'admin:catalog:category-options',
            60,
            static function (): array {
                /** @var array<string, string> */
                return CategoryRecord::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            },
        );
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando CreateCategory. El slug se normaliza en
     * el dominio; aquí se deja opcional como ayuda (si se omite, se deriva del nombre).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label(__('Slug'))
                ->helperText(__('URL-friendly identifier. Leave blank to derive it from the name.'))
                ->maxLength(255),
            Select::make('parent_id')
                ->label(__('Parent category'))
                ->native(false)
                ->searchable()
                ->preload()
                ->placeholder(__('None (top level)'))
                ->options(static fn (): array => self::categoryOptions()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: se identifica la categoría por su nombre y slug.
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->searchable()
                    ->copyable(),
                // El padre es una FK UUID: se resuelve a su nombre legible (regla "NO UUID en el admin").
                TextColumn::make('parent_id')
                    ->label(__('Parent'))
                    ->formatStateUsing(static fn (?string $state): string => $state !== null
                        ? (self::categoryOptions()[$state] ?? __('Unknown'))
                        : __('Top level'))
                    ->placeholder(__('Top level')),
                // Medidas por defecto de sus productos (escalón intermedio de la cascada logística).
                TextColumn::make('logistics_defaults')
                    ->label(__('Logistics defaults'))
                    ->state(static fn (CategoryRecord $record): string => PhysicalSpecForm::completeness($record))
                    ->badge()
                    ->color(static fn (string $state): string => PhysicalSpecForm::completenessColor($state))
                    ->formatStateUsing(static fn (string $state): string => PhysicalSpecForm::completenessLabel($state)),
            ])
            ->recordActions([
                // Medidas por defecto: despacha SetCategoryPhysicalDefaults al bus (Fase 2.0).
                PhysicalSpecForm::categoryAction(),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
        ];
    }
}
