<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\CreateNode;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\ListNodes;
use Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages\ViewNode;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin Filament de nodos de la red (CDs, tiendas, puntos de recojo).
 *
 * El admin NO es un CRUD plano: las ESCRITURAS de negocio se despachan por el CommandBus (transacción
 * + outbox), respetando el flujo Presentation → Application. El alta dispara RegisterNodeCommand
 * (ver CreateNode) y la baja dispara DeactivateNodeCommand (acción "Deactivate"). No se expone edición
 * porque el contexto no define un comando de modificación de nodo.
 *
 * Los registros se muestran por su código y nombre humanos, nunca por el UUID. Todo texto visible va
 * en inglés (regla 5 del proyecto).
 *
 * @extends resource<NodeRecord>
 */
final class NodeResource extends Resource
{
    protected static ?string $model = NodeRecord::class;

    protected static ?int $navigationSort = 10;

    // Título legible del registro para búsqueda global y selects: el código, nunca el UUID.
    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    public static function getModelLabel(): string
    {
        return __('Node');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Nodes');
    }

    /**
     * Título humano del nodo: "CODE — Name". Nunca expone el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof NodeRecord) {
            return null;
        }

        return "{$record->code} — {$record->name}";
    }

    public static function form(Schema $schema): Schema
    {
        // Campos del alta: coinciden con los argumentos de RegisterNodeCommand. El estado no se
        // captura acá (el nodo nace activo; darlo de baja es un flujo aparte).
        return $schema->components([
            TextInput::make('code')
                ->label(__('Code'))
                ->required()
                ->maxLength(64)
                ->unique(ignoreRecord: true),
            TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label(__('Type'))
                ->native(false)
                ->required()
                ->options(self::enumOptions(NodeType::cases())),
            Select::make('department')
                ->label(__('Department'))
                ->native(false)
                ->options(self::enumOptions(Department::cases()))
                ->placeholder(__('Unassigned')),
            TextInput::make('lat')
                ->label(__('Latitude'))
                ->required()
                ->numeric()
                ->minValue(-90)
                ->maxValue(90),
            TextInput::make('lng')
                ->label(__('Longitude'))
                ->required()
                ->numeric()
                ->minValue(-180)
                ->maxValue(180),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')
                ->label(__('Code')),
            TextEntry::make('name')
                ->label(__('Name')),
            TextEntry::make('type')
                ->label(__('Type'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => __(NodeType::from($state)->label())),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->color(static fn (string $state): string => $state === NodeStatus::Active->value ? 'success' : 'gray')
                ->formatStateUsing(static fn (string $state): string => __(NodeStatus::from($state)->label())),
            TextEntry::make('department')
                ->label(__('Department'))
                ->placeholder(__('Unassigned'))
                ->formatStateUsing(static fn (string $state): string => __(Department::from($state)->label())),
            TextEntry::make('lat')
                ->label(__('Latitude')),
            TextEntry::make('lng')
                ->label(__('Longitude')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(NodeType::from($state)->label())),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === NodeStatus::Active->value ? 'success' : 'gray')
                    ->formatStateUsing(static fn (string $state): string => __(NodeStatus::from($state)->label())),
                TextColumn::make('department')
                    ->label(__('Department'))
                    ->placeholder(__('Unassigned'))
                    ->formatStateUsing(static fn (?string $state): string => $state === null ? '' : __(Department::from($state)->label()))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lat')
                    ->label(__('Latitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('lng')
                    ->label(__('Longitude'))
                    ->toggleable(isToggledHiddenByDefault: true),
                // El UUID del nodo no se muestra: la URL ya lo lleva y el admin opera por código/nombre.
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->native(false)
                    ->options(self::enumOptions(NodeType::cases())),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::enumOptions(NodeStatus::cases())),
                SelectFilter::make('department')
                    ->label(__('Department'))
                    ->native(false)
                    ->options(self::enumOptions(Department::cases())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::deactivateAction(),
            ]);
    }

    /**
     * Flujo de baja de un nodo: despacha DeactivateNodeCommand al bus (transacción + outbox), nunca
     * escribe el record directamente. Solo visible cuando el nodo está activo.
     */
    public static function deactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label(__('Deactivate'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Deactivate node'))
            ->modalDescription(__('The node stays in history but will no longer accept or dispatch new load.'))
            ->visible(static fn (NodeRecord $record): bool => $record->status === NodeStatus::Active->value)
            ->action(static function (NodeRecord $record): void {
                try {
                    app(CommandBus::class)->dispatch(new DeactivateNodeCommand(
                        nodeId: (string) $record->getKey(),
                    ));
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Could not deactivate node'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Node deactivated'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListNodes::route('/'),
            'create' => CreateNode::route('/create'),
            'view' => ViewNode::route('/{record}'),
        ];
    }

    /**
     * Construye el mapa valor→etiqueta en inglés para un enum de este contexto.
     *
     * @param  array<int, NodeType|NodeStatus|Department> $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }
}
