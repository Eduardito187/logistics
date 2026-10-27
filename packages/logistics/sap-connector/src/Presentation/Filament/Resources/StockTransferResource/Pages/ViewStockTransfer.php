<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer\AcknowledgeStockTransfer;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\StockTransferResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Vista de detalle de un traspaso de stock en el panel de reconciliación (solo lectura).
 *
 * Desde el encabezado se puede disparar una corrida de despacho de los traspasos pendientes/fallidos:
 * se delega en el CommandBus (Application), nunca se toca SAP dentro del request.
 */
final class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label(__('Acknowledge'))
                ->icon('ds-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('Record SAP confirmation for this transfer. Only sent transfers can be acknowledged.'))
                ->visible(fn (): bool => $this->getRecord() instanceof StockTransferRecord
                    && $this->getRecord()->status === TransferStatus::Sent->value)
                ->action(function (CommandBus $commandBus): void {
                    /** @var StockTransferRecord $record */
                    $record = $this->getRecord();

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
                }),
            Action::make('dispatchPending')
                ->label(__('Dispatch pending transfers'))
                ->icon('ds-paper-airplane')
                ->requiresConfirmation()
                ->modalDescription(__('Push all pending and failed stock transfers to SAP now.'))
                ->action(function (CommandBus $commandBus): void {
                    $commandBus->dispatch(new DispatchPendingTransfers);

                    Notification::make()
                        ->title(__('Dispatch requested'))
                        ->body(__('Pending stock transfers were scheduled for dispatch to SAP.'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
