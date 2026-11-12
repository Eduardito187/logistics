<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\ChecklistTemplateModel;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages\CreateChecklistTemplate;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages\EditChecklistTemplate;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages\ListChecklistTemplates;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Resource de administración de las plantillas del checklist pre-salida (auditoría #27).
 *
 * Filament usa ChecklistTemplateModel solo para LECTURA; el alta y la edición despachan
 * DefineChecklistTemplate por el CommandBus (el dominio valida claves únicas y criticidad, y publica el
 * evento al outbox). La app descarga la plantilla activa por GET /app/fleet/checklist-template.
 *
 * El texto visible va íntegramente en inglés (regla 5 de CLAUDE.md).
 */
final class ChecklistTemplateResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = ChecklistTemplateModel::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 37;

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getModelLabel(): string
    {
        return __('Checklist template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Checklist templates');
    }

    /**
     * Título legible del registro: su nombre, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof ChecklistTemplateModel ? $record->name : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Template'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(120),
                    TextInput::make('vehicle_type')
                        ->label(__('Vehicle type'))
                        ->helperText(__('Leave empty for the default fleet template.'))
                        ->maxLength(40),
                    Toggle::make('active')
                        ->label(__('Active'))
                        ->default(true),
                ])
                ->columns(3),

            // Ítems en el orden en que el chofer los revisa (el orden del repeater es el `position`).
            Repeater::make('items')
                ->label(__('Checklist items'))
                ->schema([
                    TextInput::make('key')
                        ->label(__('Key'))
                        ->helperText(__('Stable code sent by the app (e.g. brakes).'))
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[a-z0-9_\-.]+$/'),
                    TextInput::make('label')
                        ->label(__('Label'))
                        ->required()
                        ->maxLength(120),
                    Select::make('category')
                        ->label(__('Category'))
                        ->required()
                        ->native(false)
                        ->options(self::categoryOptions()),
                    Select::make('criticality')
                        ->label(__('Criticality'))
                        ->required()
                        ->native(false)
                        ->default(ChecklistItemCriticality::Major->value)
                        ->options(self::criticalityOptions()),
                    Toggle::make('requires_photo')
                        ->label(__('Requires photo'))
                        ->default(false),
                ])
                ->columns(5)
                ->minItems(1)
                ->reorderable()
                ->columnSpanFull(),
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
                TextColumn::make('vehicle_type')
                    ->label(__('Vehicle type'))
                    ->placeholder(__('Default'))
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->state(static fn (ChecklistTemplateModel $record): int => count($record->items)),
                TextColumn::make('critical_count')
                    ->label(__('Critical items'))
                    ->badge()
                    ->color('danger')
                    ->state(static fn (ChecklistTemplateModel $record): int => count(array_filter(
                        $record->items,
                        static fn (array $item): bool => $item['criticality'] === ChecklistItemCriticality::Critical->value,
                    ))),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * Categorías de ítem con etiqueta en inglés.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            'mechanical' => __('Mechanical'),
            'fluids' => __('Fluids'),
            'safety' => __('Safety'),
            'tires' => __('Tires'),
            'electrical' => __('Electrical'),
            'documents' => __('Documents'),
            'cabin' => __('Cabin'),
            'cargo' => __('Cargo area'),
            'other' => __('Other'),
        ];
    }

    /**
     * Criticidades con etiqueta en inglés (desde el enum del dominio).
     *
     * @return array<string, string>
     */
    public static function criticalityOptions(): array
    {
        $options = [];
        foreach (ChecklistItemCriticality::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * Ítems del repeater a la forma primitiva que espera el comando (ignora filas incompletas).
     *
     * @return list<array{key: string, label: string, category: string, criticality: string, requires_photo: bool}>
     */
    public static function normalizeItems(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $row['key'] ?? null;
            $label = $row['label'] ?? null;
            $category = $row['category'] ?? null;
            $criticality = $row['criticality'] ?? null;
            $key = is_string($key) ? trim($key) : '';
            $label = is_string($label) ? trim($label) : '';
            if ($key === '' || $label === '') {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => $label,
                'category' => is_string($category) && $category !== '' ? $category : 'other',
                'criticality' => is_string($criticality) && $criticality !== '' ? $criticality : ChecklistItemCriticality::Major->value,
                'requires_photo' => (bool) ($row['requires_photo'] ?? false),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListChecklistTemplates::route('/'),
            'create' => CreateChecklistTemplate::route('/create'),
            'edit' => EditChecklistTemplate::route('/{record}/edit'),
        ];
    }
}
