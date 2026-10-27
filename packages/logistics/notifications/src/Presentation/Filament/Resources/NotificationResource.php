<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascade;
use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSent;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages\ListNotifications;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages\ViewNotification;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Panel del log de notificaciones al cliente (ADR 006).
 *
 * No es un CRUD: el administrador observa qué se encoló (por canal, estado y destinatario, con datos
 * humanos en vez de UUIDs) y opera la CASCADA mediante acciones que despachan los comandos reales por
 * el CommandBus (Presentation → Application), respetando invariantes y el outbox transaccional. El
 * despacho físico (push/email/SMS) sigue viviendo en el relay del outbox, nunca aquí.
 *
 * Nota de capas: Filament necesita un modelo Eloquent, que es detalle de Infrastructure. Este recurso
 * es la única pieza de Presentation que lo toca, y solo para lectura; toda escritura de negocio pasa
 * por los comandos de Application (buses CQRS), nunca escribiendo el Record directo.
 */
final class NotificationResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = NotificationRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-bell-alert';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    public static function getNavigationLabel(): string
    {
        return __('Notification Log');
    }

    public static function getModelLabel(): string
    {
        return __('notification');
    }

    public static function getPluralModelLabel(): string
    {
        return __('notifications');
    }

    /**
     * Etiquetas humanas (traducibles) para cada tipo de mensaje (nunca el valor crudo del enum).
     *
     * @return array<string, string>
     */
    public static function messageTypes(): array
    {
        return [
            'PURCHASE_CONFIRMED' => __('Purchase confirmed'),
            'ORDER_RECEIVED' => __('Order received'),
            'AT_DISTRIBUTION_CENTER' => __('At distribution center'),
            'IN_PREPARATION' => __('In preparation'),
            'LOADED' => __('Loaded'),
            'EN_ROUTE' => __('En route'),
            'ARRIVING' => __('Arriving'),
            'DELIVERED' => __('Delivered'),
            'REQUIRES_SIGNATURE' => __('Requires signature'),
            'FAILED' => __('Delivery failed'),
            'RESCHEDULED' => __('Rescheduled'),
            'READY_FOR_PICKUP' => __('Ready for pickup'),
            'PICKED_UP' => __('Picked up'),
            'CONFIRM_LOCATION' => __('Confirm location'),
        ];
    }

    /**
     * Etiquetas humanas (traducibles) para cada estado de la notificación.
     *
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'QUEUED' => __('Queued'),
            'SENT' => __('Sent'),
            'FAILED' => __('Failed'),
            'SUPPRESSED' => __('Suppressed'),
        ];
    }

    /**
     * Etiquetas humanas (traducibles) para cada canal de la cascada.
     *
     * @return array<string, string>
     */
    public static function channels(): array
    {
        return [
            'FCM' => __('Push'),
            'TELEGRAM' => __('Telegram'),
            'EMAIL' => __('Email'),
            'SMS' => __('SMS'),
        ];
    }

    /**
     * El log no se crea ni edita a mano: nace de QueueNotification y avanza por la cascada.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * Título humano de la notificación: tipo de mensaje + referencia del envío (nunca el UUID).
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof NotificationRecord) {
            return null;
        }

        $type = self::messageTypes()[$record->message_type] ?? $record->message_type;
        $shipment = $record->shipment->external_id ?? 'shipment';

        return "{$type} — {$shipment}";
    }

    /**
     * Carga anticipada del envío para mostrar referencia y cliente humanos sin N+1.
     *
     * @return Builder<NotificationRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<NotificationRecord> $query */
        $query = parent::getEloquentQuery()->with('shipment');

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('shipment.external_id')
                    ->label(__('Shipment'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (NotificationRecord $record): ?string => $record->shipment?->customer_name),
                TextColumn::make('message_type')
                    ->label(__('Message type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::messageTypes()[$state] ?? $state)
                    ->color(static fn (string $state): string => match ($state) {
                        MessageType::Delivered->value => 'success',
                        MessageType::Failed->value => 'danger',
                        MessageType::ConfirmLocation->value => 'warning',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('current_channel')
                    ->label(__('Channel'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::channels()[$state] ?? $state)
                    ->color(static fn (string $state): string => match ($state) {
                        Channel::Email->value => 'primary',
                        Channel::Sms->value => 'warning',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state)
                    ->color(static fn (string $state): string => match ($state) {
                        NotificationStatus::Sent->value => 'success',
                        NotificationStatus::Failed->value => 'danger',
                        NotificationStatus::Suppressed->value => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('recipient')
                    ->label(__('Recipient'))
                    ->state(fn (NotificationRecord $record): string => self::formatRecipient($record))
                    ->icon(fn (NotificationRecord $record): string => $record->email !== null
                        ? 'ds-envelope'
                        : ($record->phone !== null ? 'ds-device-phone-mobile' : 'ds-signal')),
                TextColumn::make('attempts')
                    ->label(__('Attempts'))
                    ->state(fn (NotificationRecord $record): int => array_sum($record->attempts))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label(__('Queued at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('message_type')
                    ->label(__('Message type'))
                    ->native(false)
                    ->options(self::messageTypes()),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statuses()),
                SelectFilter::make('current_channel')
                    ->label(__('Current channel'))
                    ->native(false)
                    ->options(self::channels()),
            ])
            ->recordActions([
                self::advanceCascadeAction(),
                self::markSentAction(),
                self::suppressAction(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('shipment.external_id')->label(__('Shipment')),
                TextEntry::make('shipment.customer_name')->label(__('Customer')),
                TextEntry::make('message_type')
                    ->label(__('Message type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::messageTypes()[$state] ?? $state),
                TextEntry::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state),
                TextEntry::make('current_channel')
                    ->label(__('Current channel'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::channels()[$state] ?? $state),
                TextEntry::make('recipient')
                    ->label(__('Recipient'))
                    ->state(fn (NotificationRecord $record): string => self::formatRecipient($record)),
                TextEntry::make('policy_channels')
                    ->label(__('Cascade'))
                    ->state(fn (NotificationRecord $record): string => self::formatCascade($record->policy['channels'] ?? [])),
                TextEntry::make('attempts_detail')
                    ->label(__('Attempts per channel'))
                    ->state(fn (NotificationRecord $record): string => self::formatAttempts($record->attempts)),
                TextEntry::make('created_at')->label(__('Queued at'))->dateTime(),
                TextEntry::make('updated_at')->label(__('Last update'))->dateTime(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListNotifications::route('/'),
            'view' => ViewNotification::route('/{record}'),
        ];
    }

    /**
     * Flujo: escalar la notificación al siguiente canal de la cascada (AdvanceCascade). Solo aplica
     * mientras sigue en cola; despacha el comando por el bus (nunca escribe el Record directo).
     */
    private static function advanceCascadeAction(): Action
    {
        return Action::make('advanceCascade')
            ->label(__('Advance cascade'))
            ->icon('ds-forward')
            ->color('warning')
            ->visible(fn (NotificationRecord $record): bool => $record->status === NotificationStatus::Queued->value)
            ->requiresConfirmation()
            ->modalHeading(__('Advance cascade'))
            ->modalDescription(__('Escalate this notification to the next channel in its cascade.'))
            ->action(function (NotificationRecord $record): void {
                self::runCommand(
                    fn (CommandBus $bus) => $bus->dispatch(new AdvanceCascade((string) $record->getKey())),
                    __('Cascade advanced'),
                );
            });
    }

    /**
     * Flujo: marcar la notificación como enviada por un canal concreto (MarkNotificationSent). Cierra
     * la cascada. El canal se elige del formulario de la acción y se mapea al argumento del comando.
     */
    private static function markSentAction(): Action
    {
        return Action::make('markSent')
            ->label(__('Mark sent'))
            ->icon('ds-check-circle')
            ->color('success')
            ->visible(fn (NotificationRecord $record): bool => $record->status === NotificationStatus::Queued->value)
            ->schema([
                Select::make('channel')
                    ->label(__('Delivered via channel'))
                    ->options(self::channels())
                    ->default(fn (NotificationRecord $record): string => (string) $record->current_channel)
                    ->native(false)
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalHeading(__('Mark notification as sent'))
            ->modalDescription(__('Record a successful delivery and close the cascade.'))
            /** @param array{channel: string} $data */
            ->action(function (NotificationRecord $record, array $data): void {
                self::runCommand(
                    fn (CommandBus $bus) => $bus->dispatch(
                        new MarkNotificationSent((string) $record->getKey(), (string) $data['channel']),
                    ),
                    __('Notification marked as sent'),
                );
            });
    }

    /**
     * Flujo: suprimir la notificación (SuppressNotification): no se enviará. Solo mientras está en cola.
     */
    private static function suppressAction(): Action
    {
        return Action::make('suppress')
            ->label(__('Suppress'))
            ->icon('ds-no-symbol')
            ->color('gray')
            ->visible(fn (NotificationRecord $record): bool => $record->status === NotificationStatus::Queued->value)
            ->requiresConfirmation()
            ->modalHeading(__('Suppress notification'))
            ->modalDescription(__('Stop this notification from being sent.'))
            ->action(function (NotificationRecord $record): void {
                self::runCommand(
                    fn (CommandBus $bus) => $bus->dispatch(new SuppressNotification((string) $record->getKey())),
                    __('Notification suppressed'),
                );
            });
    }

    /**
     * Despacha un comando por el bus y notifica éxito/error, con el mismo manejo para todas las acciones.
     *
     * @param callable(CommandBus): void $dispatch
     */
    private static function runCommand(callable $dispatch, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $dispatch($bus);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Action failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Destinatario legible: email o teléfono de respaldo, o el topic del envío (canal primario push).
     */
    private static function formatRecipient(NotificationRecord $record): string
    {
        if ($record->email !== null && $record->email !== '') {
            return $record->email;
        }

        if ($record->phone !== null && $record->phone !== '') {
            return $record->phone;
        }

        return __('App push (FCM topic)');
    }

    /**
     * Formatea la cascada de canales como etiquetas humanas en orden.
     *
     * @param list<string> $channels
     */
    private static function formatCascade(array $channels): string
    {
        if ($channels === []) {
            return '—';
        }

        return implode(' -> ', array_map(static fn (string $c): string => self::channels()[$c] ?? $c, $channels));
    }

    /**
     * Formatea los intentos por canal como texto legible para el panel de detalle.
     *
     * @param array<string, int> $attempts
     */
    private static function formatAttempts(array $attempts): string
    {
        if ($attempts === []) {
            return '—';
        }

        $parts = [];
        foreach ($attempts as $channel => $count) {
            $label = self::channels()[$channel] ?? $channel;
            $parts[] = sprintf('%s: %d', $label, $count);
        }

        return implode(', ', $parts);
    }
}
