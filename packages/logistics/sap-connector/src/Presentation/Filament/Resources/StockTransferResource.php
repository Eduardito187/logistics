<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer\AcknowledgeStockTransfer;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages;
use Logistics\Shared\Application\Command\CommandBus;
use UnitEnum;

/**
 * Panel de reconciliación de traspasos de stock a SAP (P2-SAP-04): lista los traspasos y su estado, con
 * foco en los pendientes y fallidos, y permite disparar una corrida de despacho desde el encabezado.
 *
 * Es de solo lectura: los traspasos se crean por recepción en nodo (evento → outbox → SAP), nunca a
 * mano. Excepción consciente de capas: Filament se apoya en el Record de Infrastructure; la corrida de
 * despacho se dispara por el CommandBus (Application), no tocando SAP dentro del request.
 *
 * Todo texto visible va en inglés (convención del admin de la plataforma).
 */
final class StockTransferResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = StockTransferRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string | UnitEnum | null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'SAP stock transfers';

    protected static ?string $modelLabel = 'stock transfer';

    protected static ?string $pluralModelLabel = 'stock transfers';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 32;
    }

    public static function getNavigationLabel(): string
    {
        return __('SAP stock transfers');
    }

    public static function getModelLabel(): string
    {
        return __('stock transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock transfers');
    }

    protected static ?string $recordTitleAttribute = 'shipment_ref';

    /**
     * Los traspasos no se dan de alta desde el admin: nacen de la recepción en nodo.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Vista de detalle de un traspaso (solo lectura): útil en reconciliación para inspeccionar la
     * coordenada SAP, el estado y el último error sin salir del panel.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('shipment_ref')
                ->label(__('Shipment'))
                ->copyable(),
            // event_id (UUID de la recepción) es técnico sin valor legible: no se muestra. La referencia
            // de negocio del traspaso es shipment_ref (y el SKU/material de abajo).
            TextEntry::make('mapping.sku')
                ->label(__('Platform SKU'))
                ->placeholder(__('Unmapped')),
            TextEntry::make('material')
                ->label(__('SAP material')),
            TextEntry::make('center')
                ->label(__('Center')),
            TextEntry::make('warehouse')
                ->label(__('Warehouse')),
            TextEntry::make('quantity')
                ->label(__('Quantity'))
                ->numeric(),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => __(ucfirst(strtolower($state))))
                ->color(static fn (string $state): string => match ($state) {
                    TransferStatus::Acknowledged->value => 'success',
                    TransferStatus::Sent->value => 'info',
                    TransferStatus::Failed->value => 'danger',
                    default => 'warning',
                }),
            TextEntry::make('last_error')
                ->label(__('Last error'))
                ->placeholder('—')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment_ref')
                    ->label(__('Shipment'))
                    ->searchable()
                    ->sortable(),
                // event_id (UUID de la recepción) es técnico sin valor legible: no se muestra en la tabla.
                TextColumn::make('mapping.sku')
                    ->label(__('Platform SKU'))
                    ->placeholder(__('Unmapped'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('material')
                    ->label(__('SAP material'))
                    ->searchable(),
                TextColumn::make('center')
                    ->label(__('Center')),
                TextColumn::make('warehouse')
                    ->label(__('Warehouse')),
                TextColumn::make('quantity')
                    ->label(__('Quantity'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst(strtolower($state))))
                    ->color(static fn (string $state): string => match ($state) {
                        TransferStatus::Acknowledged->value => 'success',
                        TransferStatus::Sent->value => 'info',
                        TransferStatus::Failed->value => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('last_error')
                    ->label(__('Last error'))
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('status')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options([
                        TransferStatus::Pending->value => __('Pending'),
                        TransferStatus::Sent->value => __('Sent'),
                        TransferStatus::Acknowledged->value => __('Acknowledged'),
                        TransferStatus::Failed->value => __('Failed'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::acknowledgeAction(),
            ]);
    }

    /**
     * Flujo de reconciliación: registrar la confirmación de SAP sobre un traspaso ya enviado (caso de
     * uso AcknowledgeStockTransfer). Cierra el ciclo (Sent → Acknowledged) y lo saca de los pendientes.
     *
     * Solo visible cuando el traspaso está en Sent (única transición válida). Se delega en el CommandBus,
     * que resuelve el traspaso por su reception event ID; nunca se escribe el Record a mano.
     */
    private static function acknowledgeAction(): Action
    {
        return Action::make('acknowledge')
            ->label(__('Acknowledge'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Acknowledge SAP stock transfer'))
            ->modalDescription(__('Record SAP confirmation for this transfer. Only sent transfers can be acknowledged.'))
            ->modalSubmitActionLabel(__('Acknowledge'))
            ->visible(static fn (StockTransferRecord $record): bool => $record->status === TransferStatus::Sent->value)
            ->action(function (StockTransferRecord $record, CommandBus $commandBus): void {
                try {
                    $commandBus->dispatch(new AcknowledgeStockTransfer($record->event_id));
                } catch (SapConnectorException $exception) {
                    Notification::make()
                        ->title(__('Could not acknowledge transfer'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Stock transfer acknowledged'))
                    ->body(__('SAP confirmation was recorded; the transfer is now acknowledged.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Eager-load del mapeo de material para que la columna legible "Platform SKU" no dispare N+1 al
     * resolver el SKU de cada traspaso en el panel de reconciliación.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('mapping');
    }

    /**
     * @return array<string, class-string<Page>|PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTransfers::route('/'),
            'view' => Pages\ViewStockTransfer::route('/{record}'),
        ];
    }
}
