<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ListOutboxMessages;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages\ViewOutboxMessage;

/**
 * Panel de solo lectura del Transactional Outbox: mensajes salientes hacia sistemas externos
 * (SAP, FCM, email, SMS, transportistas) con su estado y sus reintentos.
 *
 * El outbox NO se edita a mano: cada mensaje lo escribe el productor dentro de su propia transacción
 * de negocio y el relay lo despacha. Por eso el admin solo lista, ve el detalle (incluido el último
 * error y el payload) y ofrece una acción operativa de reintento que vuelve a dejar un mensaje
 * fallido/dead-letter como despachable para que el relay lo retome. Crear, editar y borrar están
 * deshabilitados.
 *
 * Nota de capas: Filament opera sobre el record Eloquent (detalle de Infrastructure) por diseño del
 * framework. Este recurso es la única pieza de Presentation que lo toca; el reintento solo reencola
 * (status Pending + available_at = ahora) para que el relay haga el despacho real por su canal.
 * Todo texto visible va en inglés (regla CLAUDE.md #5).
 *
 * El integrador registra este Resource en el panel (el package está en dont-discover y no se
 * autodescubre).
 *
 * @extends resource<OutboxMessageRecord>
 */
final class OutboxMessageResource extends Resource
{
    protected static ?string $model = OutboxMessageRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $recordTitleAttribute = 'event_name';

    public static function getNavigationGroup(): string
    {
        return __('Platform');
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
                TextColumn::make('aggregate_id')
                    ->label(__('Aggregate ID'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->limit(18),
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
            ])
            ->recordActions([
                ViewAction::make(),
                self::retryAction(),
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
            TextEntry::make('id')
                ->label(__('Message ID'))
                ->copyable()
                ->fontFamily('mono'),
            TextEntry::make('event_id')
                ->label(__('Event ID'))
                ->copyable()
                ->fontFamily('mono'),
            TextEntry::make('event_name')
                ->label(__('Event')),
            TextEntry::make('aggregate_type')
                ->label(__('Aggregate type')),
            TextEntry::make('aggregate_id')
                ->label(__('Aggregate ID'))
                ->copyable()
                ->fontFamily('mono'),
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
     * dead-letter; reencola el mensaje dejándolo disponible de inmediato para el relay.
     */
    private static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('Retry'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Retry outbox message'))
            ->modalDescription(__('Requeue this message so the relay dispatches it again.'))
            ->visible(static fn (OutboxMessageRecord $record): bool => self::isRetryable($record))
            ->action(function (OutboxMessageRecord $record): void {
                self::requeue($record);

                Notification::make()
                    ->title(__('Message requeued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción masiva de reintento: reencola los mensajes seleccionados que estén fallidos o en
     * dead-letter; ignora los ya despachados (estado terminal exitoso).
     */
    private static function retryBulkAction(): BulkAction
    {
        return BulkAction::make('retrySelected')
            ->label(__('Retry selected'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Retry selected messages'))
            ->modalDescription(__('Requeue every selected message that is failed or dead-lettered.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $requeued = 0;
                foreach ($records as $record) {
                    if ($record instanceof OutboxMessageRecord && self::isRetryable($record)) {
                        self::requeue($record);
                        $requeued++;
                    }
                }

                Notification::make()
                    ->title(__(':count message(s) requeued', ['count' => $requeued]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Un mensaje se puede reintentar salvo que ya esté despachado con éxito (estado terminal exitoso).
     */
    private static function isRetryable(OutboxMessageRecord $record): bool
    {
        return $record->status !== OutboxStatus::Dispatched->value;
    }

    /**
     * Reencola un mensaje: lo deja Pending, disponible de inmediato y con los reintentos reiniciados,
     * limpiando el último error. El despacho real lo hace el relay por el canal correspondiente.
     */
    private static function requeue(OutboxMessageRecord $record): void
    {
        $record->forceFill([
            'status' => OutboxStatus::Pending->value,
            'available_at' => now(),
            'attempts' => 0,
            'last_error' => null,
        ])->save();
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
