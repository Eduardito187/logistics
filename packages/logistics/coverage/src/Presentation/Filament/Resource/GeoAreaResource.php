<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\GeoAreaRecord;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages;

/**
 * Admin de la jerarquía geográfica administrativa (Departamento > Provincia > Municipio > Ciudad).
 *
 * Se ancla al registro Eloquent GeoAreaRecord (patrón documentado en el modelo): el admin de datos
 * maestros es el único canal de gestión de áreas, no hay comando de dominio para ellas todavía.
 *
 * Datos legibles: el área padre se muestra por su nombre a través de la relación Eloquent `parent`
 * (nunca el UUID), el nivel administrativo con etiqueta humana y color, y el UUID queda como columna
 * secundaria (toggleable, oculta por defecto).
 */
final class GeoAreaResource extends Resource
{
    protected static ?string $model = GeoAreaRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected static string | \UnitEnum | null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    public static function getNavigationSort(): int
    {
        return 22;
    }

    public static function getNavigationLabel(): string
    {
        return __('Geographic Areas');
    }

    public static function getModelLabel(): string
    {
        return __('geographic area');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geographic areas');
    }

    /**
     * Etiquetas humanas (inglés) de cada nivel administrativo. Las claves son los valores del enum.
     *
     * Se expone como método (no constante) para que cada etiqueta visible pase por __() y pueda
     * traducirse; las CLAVES siguen siendo los valores crudos del enum.
     *
     * @return array<string, string>
     */
    private static function levels(): array
    {
        return [
            AdminLevel::Department->value => __('Department'),
            AdminLevel::Province->value => __('Province'),
            AdminLevel::Municipality->value => __('Municipality'),
            AdminLevel::City->value => __('City'),
            AdminLevel::Zone->value => __('Zone'),
            AdminLevel::Microzone->value => __('Microzone'),
        ];
    }

    /**
     * Color semántico del badge por nivel (de más amplio a más específico).
     *
     * @var array<string, string>
     */
    private const LEVEL_COLORS = [
        AdminLevel::Department->value => 'danger',
        AdminLevel::Province->value => 'warning',
        AdminLevel::Municipality->value => 'info',
        AdminLevel::City->value => 'success',
        AdminLevel::Zone->value => 'primary',
        AdminLevel::Microzone->value => 'gray',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Select::make('level')
                ->label(__('Administrative level'))
                ->native(false)
                ->required()
                ->options(self::levels()),
            // Padre en el árbol: select buscable por la relación Eloquent real, excluyendo el propio
            // registro para no crear ciclos triviales. Muestra el nombre, no el UUID.
            Select::make('parent_id')
                ->label(__('Parent area'))
                ->relationship(
                    name: 'parent',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query, ?Model $record): Builder => $record === null
                        ? $query->orderBy('name')
                        : $query->whereKeyNot($record->getKey())->orderBy('name'),
                )
                ->native(false)
                ->searchable()
                ->preload()
                ->helperText(__('Leave empty for a top-level area (e.g. a department).')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('level')
                    ->label(__('Level'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::levels()[$state] ?? $state)
                    ->color(static fn (string $state): string => self::LEVEL_COLORS[$state] ?? 'gray')
                    ->sortable(),
                // Nombre del área padre resuelto por la relación Eloquent `parent` (eager-loaded).
                TextColumn::make('parent.name')
                    ->label(__('Parent area'))
                    ->placeholder(__('— top level —'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('zones_count')
                    ->label(__('Zones'))
                    ->counts('zones')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                // El UUID del área no se muestra: el admin opera por nombre y nivel.
            ])
            ->filters([
                // Filtro por nivel administrativo con etiquetas en inglés.
                SelectFilter::make('level')
                    ->label(__('Level'))
                    ->native(false)
                    ->options(self::levels()),
                // Filtro por área padre buscable con datos reales (nombres legibles).
                SelectFilter::make('parent_id')
                    ->label(__('Parent area'))
                    ->relationship('parent', 'name')
                    ->native(false)
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('name')
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
     * Eager-load del área padre para mostrar su nombre sin incurrir en N+1 al pintar la tabla.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parent');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeoAreas::route('/'),
            'create' => Pages\CreateGeoArea::route('/create'),
            'view' => Pages\ViewGeoArea::route('/{record}'),
            'edit' => Pages\EditGeoArea::route('/{record}/edit'),
        ];
    }
}
