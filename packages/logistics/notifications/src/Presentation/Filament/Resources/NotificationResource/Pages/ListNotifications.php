<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Listado del log de notificaciones.
 *
 * El encabezado expone el flujo QueueNotification: encolar una notificación de un envío. No es un alta
 * de CRUD; el formulario mapea sus campos a los argumentos del comando y lo despacha por el CommandBus
 * (resuelve la cascada del tipo de mensaje y graba el hecho en el outbox transaccional).
 */
final class ListNotifications extends ListRecords
{
    protected static string $resource = NotificationResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->queueNotificationAction(),
        ];
    }

    private function queueNotificationAction(): Action
    {
        return Action::make('queueNotification')
            ->label(__('Queue notification'))
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading(__('Queue a customer notification'))
            ->modalDescription(__('Pick the shipment and message type. Backup contacts are optional; the primary channel uses the shipment push topic.'))
            ->schema([
                Select::make('shipment_id')
                    ->label(__('Shipment'))
                    ->native(false)
                    ->required()
                    ->searchable()
                    // Búsqueda sobre datos reales de envíos (referencia externa o cliente).
                    ->getSearchResultsUsing(fn (string $search): array => ShipmentRecord::query()
                        ->where('external_id', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (ShipmentRecord $s): array => [
                            $s->getKey() => "{$s->external_id} — {$s->customer_name}",
                        ])
                        ->all())
                    ->getOptionLabelUsing(function (string $value): ?string {
                        $shipment = ShipmentRecord::query()->find($value);

                        return $shipment !== null
                            ? "{$shipment->external_id} — {$shipment->customer_name}"
                            : null;
                    }),
                Select::make('message_type')
                    ->label(__('Message type'))
                    ->options(NotificationResource::messageTypes())
                    ->native(false)
                    ->required(),
                TextInput::make('email')
                    ->label(__('Backup email (optional)'))
                    ->email()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('Backup phone (optional)'))
                    ->tel()
                    ->maxLength(32),
            ])
            /** @param array{shipment_id: string, message_type: string, email?: ?string, phone?: ?string} $data */
            ->action(function (array $data): void {
                try {
                    /** @var CommandBus $bus */
                    $bus = app(CommandBus::class);
                    $bus->dispatch(new QueueNotification(
                        shipmentId: (string) $data['shipment_id'],
                        messageType: (string) $data['message_type'],
                        email: ($data['email'] ?? '') !== '' ? (string) $data['email'] : null,
                        phone: ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : null,
                    ));

                    Notification::make()
                        ->title(__('Notification queued'))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('Could not queue notification'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
