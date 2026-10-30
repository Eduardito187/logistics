<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources;

use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages\ListCategories;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

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

    protected static ?string $model = CategoryRecord::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 30;
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
     * Se memoiza por request para no repetir la consulta en cada fila/opción.
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
