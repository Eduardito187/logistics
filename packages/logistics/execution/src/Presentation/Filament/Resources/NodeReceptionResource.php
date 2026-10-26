<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionRecord;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages\ListNodeReceptions;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages\ViewNodeReception;
use Logistics\Execution\Presentation\Filament\Support\NodeNames;
use Logistics\Execution\Presentation\Filament\Support\UnitNames;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Admin de solo lectura de las recepciones en nodo (CD→CD, CD→tienda, tienda→tienda, tienda→CD).
 *
 * Cada recepción concilia un envío que llega a un nodo destino: lo recibido (escaneos inmutables) contra
 * lo esperado, con sus faltantes y sobrantes. El admin las observa para operación y conciliación, nunca
 * las crea ni las edita; las escrituras de dominio pasan siempre por los buses CQRS en la capa HTTP.
 *
 * Todo el texto visible va en inglés (regla CLAUDE.md #5).
 *
 * @extends resource<NodeReceptionRecord>
 */
final class NodeReceptionResource extends Resource
{
    use ResolvesUserRoles;

    /**
     * Recepción en nodo: además de la oficina, la ve la tienda, que confirma la recepción en su nodo.
     *
     * Sobrescribir canAccess() basta: Filament lo usa para ocultar del menú y bloquear la URL (403).
     */
    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor', 'tienda');
    }

    protected static ?string $model = NodeReceptionRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?int $navigationSort = 34;

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Node receptions');
    }

    public static function getModelLabel(): string
    {
        return __('node reception');
    }

    public static function getPluralModelLabel(): string
    {
        return __('node receptions');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager-load del envío (para la referencia legible del pedido) y de los productos recibidos (para
     * calcular recibidos/faltantes/sobrantes sin N+1).
     *
     * @return Builder<NodeReceptionRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['shipment', 'units']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment.external_id')
                    ->label(__('Order'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('corridor')
                    ->label(__('Corridor'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::corridorLabel($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === NodeReceptionStatus::Reconciled->value ? 'success' : 'warning')
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('expected_count')
                    ->label(__('Expected'))
                    ->state(static fn (NodeReceptionRecord $record): int => count($record->expected_unit_ids))
                    ->alignCenter(),
                TextColumn::make('received_count')
                    ->label(__('Received'))
                    ->state(static fn (NodeReceptionRecord $record): int => count(self::receivedUnitIds($record)))
                    ->alignCenter(),
                TextColumn::make('missing_count')
                    ->label(__('Missing'))
                    ->state(static fn (NodeReceptionRecord $record): int => count(self::missingUnitIds($record)))
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->alignCenter(),
                TextColumn::make('surplus_count')
                    ->label(__('Surplus'))
                    ->state(static fn (NodeReceptionRecord $record): int => count(self::surplusUnitIds($record)))
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->alignCenter(),
                // Nodos mostrados por su NOMBRE legible (network_nodes.name), nunca el UUID crudo.
                TextColumn::make('destination_node_id')
                    ->label(__('Destination node'))
                    ->formatStateUsing(static fn (?string $state): string => NodeNames::label($state) ?? '—')
                    ->toggleable(),
                TextColumn::make('origin_node_id')
                    ->label(__('Origin node'))
                    ->formatStateUsing(static fn (?string $state): string => NodeNames::label($state) ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opened_at')
                    ->label(__('Opened at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('reconciled_at')
                    ->label(__('Reconciled at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('corridor')
                    ->label(__('Corridor'))
                    ->native(false)
                    ->options(self::corridorOptions()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->defaultSort('opened_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shipment.external_id')->label(__('Order'))->placeholder('—'),
                TextEntry::make('corridor')
                    ->label(__('Corridor'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::corridorLabel($state)),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => $state === NodeReceptionStatus::Reconciled->value ? 'success' : 'warning')
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state)),
                // Nodos mostrados por su NOMBRE legible (network_nodes.name), nunca el UUID crudo.
                TextEntry::make('origin_node_id')
                    ->label(__('Origin node'))
                    ->formatStateUsing(static fn (?string $state): string => NodeNames::label($state) ?? '—'),
                TextEntry::make('destination_node_id')
                    ->label(__('Destination node'))
                    ->formatStateUsing(static fn (?string $state): string => NodeNames::label($state) ?? '—'),
                // Los productos se listan por su etiqueta legible (pedido + índice de producto/pieza), nunca
                // por el UUID crudo: cada id se resuelve con UnitNames por badge.
                TextEntry::make('expected_unit_ids')
                    ->label(__('Expected units'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => UnitNames::label($state))
                    ->placeholder('—'),
                TextEntry::make('received_unit_ids')
                    ->label(__('Received units'))
                    ->state(static fn (NodeReceptionRecord $record): array => self::receivedUnitIds($record))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => UnitNames::label($state))
                    ->placeholder('—'),
                TextEntry::make('missing_unit_ids')
                    ->label(__('Missing units'))
                    ->state(static fn (NodeReceptionRecord $record): array => self::missingUnitIds($record))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => UnitNames::label($state))
                    ->color('danger')
                    ->placeholder('—'),
                TextEntry::make('surplus_unit_ids')
                    ->label(__('Surplus units'))
                    ->state(static fn (NodeReceptionRecord $record): array => self::surplusUnitIds($record))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => UnitNames::label($state))
                    ->color('warning')
                    ->placeholder('—'),
                TextEntry::make('opened_at')->label(__('Opened at'))->dateTime(),
                TextEntry::make('reconciled_at')->label(__('Reconciled at'))->dateTime()->placeholder('—'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListNodeReceptions::route('/'),
            'view' => ViewNodeReception::route('/{record}'),
        ];
    }

    /**
     * Ids de los productos recibidos (únicos), leídos de la relación cargada.
     *
     * @return list<string>
     */
    private static function receivedUnitIds(NodeReceptionRecord $record): array
    {
        $ids = [];
        foreach ($record->units as $unit) {
            if (!in_array($unit->unit_id, $ids, true)) {
                $ids[] = $unit->unit_id;
            }
        }

        return $ids;
    }

    /**
     * Faltantes: esperados que no se recibieron.
     *
     * @return list<string>
     */
    private static function missingUnitIds(NodeReceptionRecord $record): array
    {
        $received = self::receivedUnitIds($record);

        return array_values(array_filter(
            $record->expected_unit_ids,
            static fn (string $expected): bool => !in_array($expected, $received, true),
        ));
    }

    /**
     * Sobrantes: recibidos que no se esperaban.
     *
     * @return list<string>
     */
    private static function surplusUnitIds(NodeReceptionRecord $record): array
    {
        return array_values(array_filter(
            self::receivedUnitIds($record),
            static fn (string $received): bool => !in_array($received, $record->expected_unit_ids, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function corridorOptions(): array
    {
        $options = [];
        foreach (Corridor::cases() as $case) {
            $options[$case->value] = self::corridorLabel($case->value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (NodeReceptionStatus::cases() as $case) {
            $options[$case->value] = self::statusLabel($case->value);
        }

        return $options;
    }

    /**
     * Etiqueta en inglés para un corredor almacenado.
     */
    private static function corridorLabel(string $value): string
    {
        return match ($value) {
            Corridor::CdToCd->value => __('DC to DC'),
            Corridor::CdToStore->value => __('DC to store'),
            Corridor::StoreToStore->value => __('Store to store'),
            Corridor::StoreToCd->value => __('Store to DC'),
            default => $value,
        };
    }

    /**
     * Etiqueta en inglés para un estado de recepción almacenado.
     */
    private static function statusLabel(string $value): string
    {
        return match ($value) {
            NodeReceptionStatus::Open->value => __('Open'),
            NodeReceptionStatus::Reconciled->value => __('Reconciled'),
            default => $value,
        };
    }
}
