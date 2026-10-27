<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\Outbox\Application\Command\DiscardMessage\DiscardMessageCommand;
use Logistics\Outbox\Application\Command\RetryMessage\RetryMessageCommand;
use Logistics\Outbox\Application\Query\GetOutboxStats\GetOutboxStatsQuery;
use Logistics\Outbox\Application\ReadModel\OutboxStatsView;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ListOutboxMessages;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ViewOutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Panel de solo lectura del Transactional Outbox: mensajes salientes hacia sistemas externos
 * (SAP, FCM, email, SMS, transportistas) con su estado y sus reintentos.
 *
 * El outbox NO se edita a mano: cada mensaje lo escribe el productor dentro de su propia transacción
 * de negocio y el relay lo despacha. Por eso el admin solo lista, ve el detalle (incluido el último
 * error y el payload) y ofrece dos acciones operativas: reintentar (reencola un mensaje fallido o en
 * dead-letter para que el relay lo retome) y mover a dead-letter (saca de la cola un mensaje que nunca
 * va a poder despacharse). Crear, editar y borrar están deshabilitados.
 *
 * Nota de capas: Filament LEE sobre el record Eloquent (detalle de Infrastructure) por diseño del
 * framework; es la única pieza de Presentation que lo toca y solo para observar/filtrar. Toda ESCRITURA
 * pasa por el CommandBus (RetryMessageCommand / DiscardMessageCommand): se respeta Presentation →
 * Application y el dominio valida la transición (p. ej. no se reencola un mensaje ya despachado). El
 * reintento solo reencola (status Pending + available_at = ahora); el despacho real lo hace el relay por
 * su canal. El contador de navegación consulta GetOutboxStats por el QueryBus.
 *
 * Todo texto visible va en inglés (regla CLAUDE.md #5).
 *
 * El integrador registra este Resource en el panel (el package está en dont-discover y no se
 * autodescubre).
 *
 * @extends resource<OutboxMessageRecord>
 */
final class OutboxMessageResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = OutboxMessageRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'ds-paper-airplane';

    protected static ?string $recordTitleAttribute = 'event_name';

    public static function getNavigationGroup(): string
    {
        return __('Administration');
    }

    public static function getNavigationSort(): int
    {
        return 50;
    }

    public static function getNavigationLabel(): string
    {
        return __('Outbox messages');
    }

    public static function getModelLabel(): string
    {
        return __('outbox message');
    }

    public static function getPluralModelLabel(): string
    {
        return __('outbox messages');
    }

    /**
     * Contador de navegación: mensajes que requieren atención operativa, es decir, la cola viva
     * (pendientes + fallidos esperando su reintento) más los que están en dead-letter. Si hay
     * dead-letters, el total aparece en rojo (alerta); si solo hay cola viva, en ámbar.
     */
    public static function getNavigationBadge(): ?string
    {
        $stats = self::stats();
        $outstanding = $stats->backlog() + $stats->deadLettered;

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $stats = self::stats();

        if ($stats->deadLettered > 0) {
            return 'danger';
        }

        return $stats->backlog() > 0 ? 'warning' : null;
    }

    public static function getNavigationBadgeTooltip(): string
    {
        $stats = self::stats();

        return __(':pending pending · :failed failed · :dead dead-lettered', [
            'pending' => $stats->pending,
            'failed' => $stats->failed,
            'dead' => $stats->deadLettered,
        ]);
    }

    /**
     * El outbox es inmutable desde el admin: los mensajes los crea el productor en su transacción.
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event_name')
                    ->label(__('Event'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('aggregate_type')
                    ->label(__('Aggregate type'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                // aggregate_id es un UUID técnico sin relación legible: no se muestra (basta aggregate_type + event_name).
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('attempts')
                    ->label(__('Attempts'))
                    ->numeric()
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('available_at')
                    ->label(__('Available at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('dispatched_at')
                    ->label(__('Dispatched at'))
                    ->dateTime()
                    ->placeholder(__('Not dispatched'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label(__('Last error'))
                    ->placeholder(__('No error'))
                    ->limit(40)
                    ->tooltip(static fn (?string $state): ?string => $state)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
                SelectFilter::make('aggregate_type')
                    ->label(__('Aggregate type'))
                    ->native(false)
                    ->searchable()
                    ->options(self::aggregateTypeOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::retryAction(),
                self::discardAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::retryBulkAction(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            // id, event_id y aggregate_id son UUID técnicos sin relación legible: no se muestran.
            TextEntry::make('event_name')
                ->label(__('Event')),
            TextEntry::make('aggregate_type')
                ->label(__('Aggregate type')),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                ->color(static fn (string $state): string => self::statusColor($state)),
            TextEntry::make('attempts')
                ->label(__('Attempts')),
            TextEntry::make('available_at')
                ->label(__('Available at'))
                ->dateTime(),
            TextEntry::make('created_at')
                ->label(__('Created at'))
                ->dateTime(),
            TextEntry::make('dispatched_at')
                ->label(__('Dispatched at'))
                ->dateTime()
                ->placeholder(__('Not dispatched')),
            TextEntry::make('last_error')
                ->label(__('Last error'))
                ->placeholder(__('No error'))
                ->columnSpanFull(),
            TextEntry::make('payload')
                ->label(__('Payload'))
                ->state(static fn (OutboxMessageRecord $record): string => self::formatPayload($record->payload))
                ->fontFamily('mono')
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOutboxMessages::route('/'),
            'view' => ViewOutboxMessage::route('/{record}'),
        ];
    }

    /**
     * Acción de reintento por fila: solo habilitada sobre mensajes despachables (Pending/Failed) o
     * dead-letter; despacha RetryMessageCommand, que reencola el mensaje dejándolo disponible de
     * inmediato para el relay.
     */
    private static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('Retry'))
            ->icon('ds-arrow-path')
            ->extraAttributes(['class' => 'ds-icon-spin-hover'])
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Retry outbox message'))
            ->modalDescription(__('Requeue this message so the relay dispatches it again.'))
            ->visible(static fn (OutboxMessageRecord $record): bool => self::isRetryable($record))
            ->action(static function (OutboxMessageRecord $record): void {
                self::dispatch(
                    new RetryMessageCommand((string) $record->getKey()),
                    __('Message requeued'),
                );
            });
    }

    /**
     * Acción masiva de reintento: reencola los mensajes seleccionados que sean reencolables (fallidos o
     * en dead-letter), despachando un RetryMessageCommand por cada uno. Ignora los ya despachados
     * (estado terminal exitoso) y reporta cuántos se reencolaron de verdad.
     */
    private static function retryBulkAction(): BulkAction
    {
        return BulkAction::make('retrySelected')
            ->label(__('Retry selected'))
            ->icon('ds-arrow-path')
            ->extraAttributes(['class' => 'ds-icon-spin-hover'])
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Retry selected messages'))
            ->modalDescription(__('Requeue every selected message that is failed or dead-lettered.'))
            ->deselectRecordsAfterCompletion()
            ->action(static function (Collection $records): void {
                /** @var CommandBus $bus */
                $bus = app(CommandBus::class);

                $requeued = 0;
                foreach ($records as $record) {
                    if (!$record instanceof OutboxMessageRecord || !self::isRetryable($record)) {
                        continue;
                    }

                    try {
                        $bus->dispatch(new RetryMessageCommand((string) $record->getKey()));
                        $requeued++;
                    } catch (OutboxException) {
                        // Mensaje no reencolable (p. ej. despachado entre la selección y el click): se omite.
                    }
                }

                Notification::make()
                    ->title(__(':count message(s) requeued', ['count' => $requeued]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción de descarte por fila: manda a dead-letter un mensaje despachable (Pending/Failed) sin
     * esperar a agotar los reintentos. Pide un motivo obligatorio y despacha DiscardMessageCommand. No
     * se ofrece sobre estados terminales (ya despachado o ya en dead-letter).
     */
    private static function discardAction(): Action
    {
        return Action::make('discard')
            ->label(__('Move to dead-letter'))
            ->icon('ds-archive-box-x-mark')
            ->color('danger')
            ->visible(static fn (OutboxMessageRecord $record): bool => self::isDispatchable($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->modalHeading(__('Move message to dead-letter'))
            ->modalDescription(__('Stop retrying this message and move it to dead-letter for manual intervention.'))
            ->action(static function (OutboxMessageRecord $record, array $data): void {
                self::dispatch(
                    new DiscardMessageCommand((string) $record->getKey(), (string) $data['reason']),
                    __('Message moved to dead-letter'),
                );
            });
    }

    /**
     * Despacha un comando operativo por el CommandBus y notifica el resultado. Un fallo de dominio
     * (transición ilegal) se reporta como notificación y detiene el flujo (Halt) para que el modal no se
     * cierre en silencio.
     */
    private static function dispatch(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);
        } catch (OutboxException $exception) {
            Notification::make()
                ->title(__('Could not complete the operation'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    /**
     * Un mensaje se puede reintentar salvo que ya esté despachado con éxito (estado terminal exitoso).
     */
    private static function isRetryable(OutboxMessageRecord $record): bool
    {
        return $record->status !== OutboxStatus::Dispatched->value;
    }

    /**
     * ¿El mensaje sigue en la cola (Pending/Failed)? Solo esos se pueden mandar a dead-letter a mano;
     * los terminales (despachado / ya en dead-letter) no.
     */
    private static function isDispatchable(OutboxMessageRecord $record): bool
    {
        return in_array($record->status, [
            OutboxStatus::Pending->value,
            OutboxStatus::Failed->value,
        ], true);
    }

    /**
     * Conteo por estado del outbox (ReadModel) vía QueryBus. Insumo del contador de navegación.
     */
    private static function stats(): OutboxStatsView
    {
        /** @var OutboxStatsView $stats */
        $stats = app(QueryBus::class)->ask(new GetOutboxStatsQuery);

        return $stats;
    }

    /**
     * Opciones del filtro de estado: valor del enum => etiqueta legible en inglés.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (OutboxStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status->value);
        }

        return $options;
    }

    /**
     * Opciones del filtro por tipo de agregado: valores reales presentes en la tabla (p. ej.
     * "network.node", "shipments.shipment", "sap.transfer"), ordenados alfabéticamente.
     *
     * @return array<string, string>
     */
    private static function aggregateTypeOptions(): array
    {
        /** @var array<string, string> $options */
        $options = OutboxMessageRecord::query()
            ->select('aggregate_type')
            ->distinct()
            ->orderBy('aggregate_type')
            ->pluck('aggregate_type', 'aggregate_type')
            ->all();

        return $options;
    }

    /**
     * Etiqueta humana en inglés para un estado del outbox.
     */
    private static function statusLabel(string $state): string
    {
        return match ($state) {
            OutboxStatus::Pending->value => __('Pending'),
            OutboxStatus::Failed->value => __('Failed'),
            OutboxStatus::Dispatched->value => __('Dispatched'),
            OutboxStatus::DeadLettered->value => __('Dead-lettered'),
            default => $state,
        };
    }

    /**
     * Color del badge por estado, siguiendo la paleta semántica de Filament.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            OutboxStatus::Dispatched->value => 'success',
            OutboxStatus::Failed->value => 'warning',
            OutboxStatus::DeadLettered->value => 'danger',
            default => 'gray',
        };
    }

    /**
     * Formatea el payload JSON de forma legible para el panel de detalle.
     *
     * @param array<string, mixed> $payload
     */
    private static function formatPayload(array $payload): string
    {
        if ($payload === []) {
            return '—';
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
