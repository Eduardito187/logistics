<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages;
use Logistics\Coverage\Presentation\Filament\Support\NodeNames;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de zonas de cobertura y sus reglas de operación (CoverageRule por zona).
 *
 * El polígono de cada zona proviene de la importación del mapa (GeoJSON) o del comando DefineZone,
 * porque exige geometría PostGIS; por eso este resource no crea zonas: solo lista, inspecciona y
 * edita las reglas de las existentes. La edición de reglas se despacha por el bus CQRS
 * (UpdateZoneRules), nunca escribiendo el jsonb a mano, para pasar por las invariantes del dominio.
 */
final class ZoneResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = ZoneRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'ds-globe-americas';

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
        return 20;
    }

    public static function getNavigationLabel(): string
    {
        return __('Coverage Zones');
    }

    public static function getModelLabel(): string
    {
        return __('coverage zone');
    }

    public static function getPluralModelLabel(): string
    {
        return __('coverage zones');
    }

    /**
     * Etiquetas visibles (inglés) de los días de servicio. Las CLAVES (1..7) son los valores crudos.
     *
     * Se expone como método para que cada etiqueta pase por __() y pueda traducirse.
     *
     * @return array<int, string>
     */
    private static function serviceDays(): array
    {
        return [
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Zone'))
                ->description(__('Identity and administrative placement of the zone.'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    // Área administrativa padre: select buscable por la relación Eloquent real
                    // (muestra el nombre del área, no el UUID).
                    Select::make('parent_area_id')
                        ->label(__('Parent area'))
                        ->relationship('parentArea', 'name')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->helperText(__('The administrative area this zone belongs to.')),
                    Toggle::make('active')
                        ->label(__('Active'))
                        ->default(true),
                ])
                ->columns(2),
            Section::make(__('Coverage rule'))
                ->description(__('Operating rules applied to deliveries inside this zone.'))
                ->schema([
                    // Nodo que atiende la zona: select buscable con nodos reales de la red. Se lee la
                    // tabla por consulta directa para no acoplar este contexto al package network
                    // (cada contexto es extraíble a microservicio); al extraerse pasará a una API de red.
                    Select::make('rules.nodeId')
                        ->label(__('Serving node'))
                        ->native(false)
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => DB::table('network_nodes')
                            ->where(function ($query) use ($search): void {
                                $query->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('code', 'ilike', "%{$search}%");
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(static fn ($node): array => [$node->id => "{$node->name} ({$node->code})"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => NodeNames::label(is_string($value) ? $value : (string) $value))
                        ->helperText(__('Node that fulfils deliveries in this zone.')),
                    TextInput::make('rules.baseMinutes')
                        ->label(__('Base lead time (minutes)'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    TagsInput::make('rules.services')
                        ->label(__('Services'))
                        ->placeholder('STD, EXPRESS')
                        ->helperText(__('Service codes enabled in this zone.')),
                    CheckboxList::make('rules.serviceDays')
                        ->label(__('Service days'))
                        ->options(self::serviceDays())
                        ->columns(3),
                    KeyValue::make('rules.productLimits')
                        ->label(__('Product limits'))
                        ->keyLabel(__('SKU'))
                        ->valueLabel(__('Max units'))
                        ->helperText(__('Maximum units per product allowed in this zone.')),
                    Repeater::make('rules.timeWindows')
                        ->label(__('Time windows'))
                        ->schema([
                            TextInput::make('start')
                                ->label(__('Start'))
                                ->placeholder('08:00')
                                ->required(),
                            TextInput::make('end')
                                ->label(__('End'))
                                ->placeholder('18:00')
                                ->required(),
                        ])
                        ->columns(2)
                        ->helperText(__('Daily service windows (24h HH:MM).')),
                ])
                ->columns(2),
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
                // Nombre del área padre vía la relación Eloquent `parentArea` (eager-loaded).
                TextColumn::make('parentArea.name')
                    ->label(__('Parent area'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('serving_node')
                    ->label(__('Serving node'))
                    ->placeholder('—')
                    // Sin N+1 (auditoría M-3): se resuelve contra el mapa memoizado id→"name (code)",
                    // una sola lectura de `network_nodes` por request en vez de una por fila.
                    ->getStateUsing(function (ZoneRecord $record): ?string {
                        $nodeId = $record->rules['nodeId'] ?? null;
                        if (!is_string($nodeId) || $nodeId === '') {
                            return null;
                        }

                        return NodeNames::label($nodeId);
                    }),
                TextColumn::make('services')
                    ->label(__('Services'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->getStateUsing(function (ZoneRecord $record): array {
                        $services = $record->rules['services'] ?? [];

                        return is_array($services) ? array_values($services) : [];
                    }),
                TextColumn::make('base_minutes')
                    ->label(__('Base lead time'))
                    ->placeholder('—')
                    ->getStateUsing(function (ZoneRecord $record): ?string {
                        $base = $record->rules['baseMinutes'] ?? null;

                        return is_numeric($base) ? ((int) $base).' '.__('min') : null;
                    }),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
                // El UUID de la zona no se muestra: el admin opera por nombre y área padre.
            ])
            ->filters([
                // Estado de la zona (activa / inactiva).
                TernaryFilter::make('active')
                    ->label(__('Active')),
                // Área administrativa padre: filtro buscable por la relación Eloquent (nombres reales).
                SelectFilter::make('parent_area_id')
                    ->label(__('Parent area'))
                    ->relationship('parentArea', 'name')
                    ->native(false)
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('name')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Eager-load del área padre para pintar su nombre en la tabla sin N+1.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('parentArea');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZones::route('/'),
            'view' => Pages\ViewZone::route('/{record}'),
            'edit' => Pages\EditZone::route('/{record}/edit'),
        ];
    }
}
