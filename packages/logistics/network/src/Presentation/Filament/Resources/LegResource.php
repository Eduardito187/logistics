<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegCommand;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Infrastructure\Persistence\Eloquent\LegRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\CreateLeg;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\ListLegs;
use Logistics\Network\Presentation\Filament\Resources\LegResource\Pages\ViewLeg;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin Filament de tramos/corredores entre nodos.
 *
 * El admin NO es un CRUD plano: el alta dispara DefineLegCommand (ver CreateLeg) y el borrado dispara
 * RemoveLegCommand (acción "Remove"), ambos por el CommandBus (transacción + outbox). No se expone
 * edición porque un tramo es inmutable: para cambiarlo se elimina y se define otro.
 *
 * Los tramos se muestran como origen → destino por NOMBRE de nodo (relación Eloquent), nunca por UUID;
 * los nodos se eager-loadean para evitar N+1. Todo texto visible va en inglés (regla 5 del proyecto).
 *
 * @extends resource<LegRecord>
 */
final class LegResource extends Resource
{
    protected static ?string $model = LegRecord::class;

    protected static ?int $navigationSort = 20;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public static function getNavigationGroup(): string
    {
        return __('Network');
    }

    public static function getModelLabel(): string
    {
        return __('Corridor leg');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Corridor legs');
    }

    /**
     * Eager-load de los nodos de origen y destino: las columnas y el título del tramo los muestran por
     * nombre, así que se precargan para no incurrir en N+1.
     *
     * @return Builder<LegRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['fromNode', 'toNode']);
    }

    /**
     * Título humano del tramo: "Origin → Destination" por nombre de nodo. Nunca expone el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof LegRecord) {
            return null;
        }

        $from = $record->fromNode->name ?? '—';
        $to = $record->toNode->name ?? '—';

        return "{$from} → {$to}";
    }

    public static function form(Schema $schema): Schema
    {
        // Campos del alta: coinciden con los argumentos de DefineLegCommand. Los nodos se eligen por
        // un select buscable alimentado con los nodos reales (código y nombre), nunca pegando un UUID.
        return $schema->components([
            Select::make('from_node_id')
                ->label(__('From node'))
                ->relationship('fromNode', 'name')
                ->getOptionLabelFromRecordUsing(static fn (NodeRecord $record): string => "{$record->code} — {$record->name}")
                ->searchable(['code', 'name'])
                ->preload()
                ->native(false)
                ->required(),
            Select::make('to_node_id')
                ->label(__('To node'))
                ->relationship('toNode', 'name')
                ->getOptionLabelFromRecordUsing(static fn (NodeRecord $record): string => "{$record->code} — {$record->name}")
                ->searchable(['code', 'name'])
                ->preload()
                ->native(false)
                ->required()
                ->different('from_node_id'),
            Select::make('corridor_type')
                ->label(__('Corridor type'))
                ->native(false)
                ->required()
                ->options(self::corridorOptions()),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('fromNode.code')
                ->label(__('From node code')),
            TextEntry::make('fromNode.name')
                ->label(__('From node')),
            TextEntry::make('toNode.code')
                ->label(__('To node code')),
            TextEntry::make('toNode.name')
                ->label(__('To node')),
            TextEntry::make('corridor_type')
                ->label(__('Corridor type'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => __(CorridorType::from($state)->label())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fromNode.name')
                    ->label(__('From node'))
                    ->description(static fn (LegRecord $record): ?string => $record->fromNode?->code)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('toNode.name')
                    ->label(__('To node'))
                    ->description(static fn (LegRecord $record): ?string => $record->toNode?->code)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(CorridorType::from($state)->label())),
                // Identificador técnico: oculto por defecto, disponible para soporte.
                TextColumn::make('id')
                    ->label(__('Internal ID'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->native(false)
                    ->options(self::corridorOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::removeAction(),
            ]);
    }

    /**
     * Flujo de eliminación de un tramo: despacha RemoveLegCommand al bus (transacción + outbox), nunca
     * borra el record directamente.
     */
    public static function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('Remove'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Remove corridor leg'))
            ->modalDescription(__('The leg is removed from the network. Shipments already planned are not affected.'))
            ->action(static function (LegRecord $record): void {
                try {
                    app(CommandBus::class)->dispatch(new RemoveLegCommand(
                        legId: (string) $record->getKey(),
                    ));
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Could not remove leg'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Corridor leg removed'))
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
            'index' => ListLegs::route('/'),
            'create' => CreateLeg::route('/create'),
            'view' => ViewLeg::route('/{record}'),
        ];
    }

    /**
     * Mapa valor→etiqueta en inglés de los corredores.
     *
     * @return array<string, string>
     */
    private static function corridorOptions(): array
    {
        $options = [];
        foreach (CorridorType::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }
}
