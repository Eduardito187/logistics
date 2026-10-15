<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Listado del panel de reconciliación de traspasos a SAP. Expone los casos de uso del contexto como
 * flujos transaccionales (no CRUD): encolar un traspaso y correr el despacho de los pendientes/fallidos.
 * Ambos se delegan en el CommandBus (Application), que respeta invariantes y outbox; nunca se escribe el
 * Record a mano ni se toca SAP dentro del request.
 *
 * Las pestañas organizan la reconciliación: por defecto se ven los pendientes y los fallidos, que son
 * los que exigen acción del operador.
 */
final class ListStockTransfers extends ListRecords
{
    protected static string $resource = StockTransferResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->enqueueTransferAction(),
            $this->dispatchPendingAction(),
        ];
    }

    /**
     * Flujo: encolar un traspaso de stock a SAP (caso de uso EnqueueStockTransfer). El operador elige el
     * SKU de la plataforma desde un select buscable con datos reales del mapa de materiales; el handler
     * traduce a la coordenada SAP y persiste el traspaso Pending. Idempotente por reception event ID.
     */
    private function enqueueTransferAction(): Action
    {
        return Action::make('enqueueTransfer')
            ->label(__('Enqueue stock transfer'))
            ->icon('heroicon-o-inbox-arrow-down')
            ->modalHeading(__('Enqueue stock transfer to SAP'))
            ->modalDescription(__('Queue a stock transfer from a node reception. SAP is pushed later by the dispatch worker.'))
            ->modalSubmitActionLabel(__('Enqueue'))
            ->schema([
                TextInput::make('eventId')
                    ->label(__('Reception event ID'))
                    ->helperText(__('Idempotency key: re-enqueuing the same event does not duplicate the transfer.'))
                    ->required()
                    ->maxLength(64),
                TextInput::make('shipmentRef')
                    ->label(__('Shipment reference'))
                    ->required()
                    ->maxLength(64),
                Select::make('sku')
                    ->label(__('Platform SKU'))
                    ->helperText(__('Translated to the SAP coordinate (material, center, warehouse) by the material mapping.'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => MaterialMappingRecord::query()
                        ->where('sku', 'like', "%{$search}%")
                        ->orderBy('sku')
                        ->limit(50)
                        ->pluck('sku', 'sku')
                        ->all())
                    ->getOptionLabelUsing(static fn (string $value): ?string => MaterialMappingRecord::query()
                        ->where('sku', $value)
                        ->value('sku')),
                TextInput::make('quantity')
                    ->label(__('Quantity'))
                    ->required()
                    ->numeric()
                    ->minValue(1),
            ])
            ->action(function (array $data, CommandBus $commandBus): void {
                try {
                    $commandBus->dispatch(new EnqueueStockTransfer(
                        (string) $data['eventId'],
                        (string) $data['shipmentRef'],
                        (string) $data['sku'],
                        (int) $data['quantity'],
                    ));
                } catch (SapConnectorException $exception) {
                    Notification::make()
                        ->title(__('Could not enqueue transfer'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Stock transfer enqueued'))
                    ->body(__('The transfer is pending and will be pushed to SAP by the dispatch worker.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Flujo de reconciliación: correr el despacho de todos los traspasos pendientes y fallidos (caso de
     * uso DispatchPendingTransfers). Es el reintento del panel: toma Pending y Failed y los empuja a SAP.
     */
    private function dispatchPendingAction(): Action
    {
        return Action::make('dispatchPending')
            ->label(__('Dispatch pending transfers'))
            ->icon('heroicon-o-paper-airplane')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('Push all pending and failed stock transfers to SAP now. Failed transfers are retried.'))
            ->action(function (CommandBus $commandBus): void {
                $commandBus->dispatch(new DispatchPendingTransfers);

                Notification::make()
                    ->title(__('Dispatch requested'))
                    ->body(__('Pending and failed stock transfers were scheduled for dispatch to SAP.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Pestañas de reconciliación: pendientes y fallidos primero (los accionables), más vistas de los
     * enviados, confirmados y el total.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make(__('Pending'))
                ->modifyQueryUsing(static function (Builder $query): Builder {
                    /** @var Builder<StockTransferRecord> $query */
                    return $query->where('status', TransferStatus::Pending->value);
                }),
            'failed' => Tab::make(__('Failed'))
                ->modifyQueryUsing(static function (Builder $query): Builder {
                    /** @var Builder<StockTransferRecord> $query */
                    return $query->where('status', TransferStatus::Failed->value);
                }),
            'sent' => Tab::make(__('Sent'))
                ->modifyQueryUsing(static function (Builder $query): Builder {
                    /** @var Builder<StockTransferRecord> $query */
                    return $query->where('status', TransferStatus::Sent->value);
                }),
            'acknowledged' => Tab::make(__('Acknowledged'))
                ->modifyQueryUsing(static function (Builder $query): Builder {
                    /** @var Builder<StockTransferRecord> $query */
                    return $query->where('status', TransferStatus::Acknowledged->value);
                }),
            'all' => Tab::make(__('All')),
        ];
    }
}
