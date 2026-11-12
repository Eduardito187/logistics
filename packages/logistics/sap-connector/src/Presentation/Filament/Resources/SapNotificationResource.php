<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Logistics\Access\Presentation\Filament\Concerns\AdminOnly;
use Logistics\SapConnector\Application\Command\RetrySapNotification\RetrySapNotification;
use Logistics\SapConnector\Application\Notification\SapNotifiableEvents;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\SapNotificationRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\SapNotificationResource\Pages;
use Logistics\Shared\Application\Command\CommandBus;
use UnitEnum;

/**
 * Bitácora de avisos fire-and-forget a SAP (solo lectura): qué hecho se avisó, cómo terminó, cuántos
 * intentos llevó y el último error. Es diagnóstico puro: nada de esto afecta a la operación.
 *
 * Regla del admin: NUNCA se muestran UUID (ni id, ni event_id, ni aggregate_id). El evento se muestra
 * con su nombre humano del catálogo. La única escritura es la acción opcional «Retry», que va por el
 * CommandBus (RetrySapNotification), nunca tocando el Record a mano.
 *
 * Todo texto visible va en inglés (convención del admin), traducido por lang/es.json.
 */
final class SapNotificationResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = SapNotificationRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-megaphone';

    protected static string | UnitEnum | null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'SAP notifications';

    protected static ?string $modelLabel = 'SAP notification';

    protected static ?string $pluralModelLabel = 'SAP notifications';

    public static function getNavigationGroup(): string
    {
        return __('Integrations');
    }

    public static function getNavigationSort(): int
    {
        return 23;
    }

    public static function getNavigationLabel(): string
    {
        return __('SAP notifications');
    }

    public static function getModelLabel(): string
    {
        return __('SAP notification');
    }

    public static function getPluralModelLabel(): string
    {
        return __('SAP notifications');
    }

    public static function canCreate(): bool
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
            ->deferLoading()
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event_name')
                    ->label(__('Event'))
                    ->formatStateUsing(static fn (string $state): string => __(SapNotifiableEvents::label($state))),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('attempts')
                    ->label(__('Attempts'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label(__('Last error'))
                    ->placeholder('—')
                    ->limit(60)
                    ->tooltip(static fn (?string $state): ?string => $state)
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label(__('Sent at'))
                    ->dateTime()
                    ->placeholder(__('Not sent'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
                SelectFilter::make('event_name')
                    ->label(__('Event'))
                    ->native(false)
                    ->options(self::eventOptions()),
            ])
            ->recordActions([
                self::retryAction(),
            ]);
    }

    /**
     * Reintento opcional de un aviso no enviado (Failed/Skipped): reinicia los intentos y re-encola el
     * envío en la cola `sap` por el CommandBus. La plataforma no lo necesita; es solo para operación.
     */
    private static function retryAction(): Action
    {
        return Action::make('retry')
            ->label(__('Retry'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Retry SAP notification'))
            ->modalDescription(__('Reset the attempts and send this notification to SAP again in the background.'))
            ->visible(static fn (SapNotificationRecord $record): bool => in_array(
                $record->status,
                [SapNotificationStatus::Failed->value, SapNotificationStatus::Skipped->value],
                true,
            ))
            ->action(function (SapNotificationRecord $record, CommandBus $commandBus): void {
                try {
                    $commandBus->dispatch(new RetrySapNotification($record->id));
                } catch (SapConnectorException $exception) {
                    Notification::make()
                        ->title(__('Could not retry the SAP notification'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('SAP notification queued'))
                    ->body(__('It will be sent to SAP in the background.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (SapNotificationStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status->value);
        }

        return $options;
    }

    /**
     * Opciones del filtro por evento: el catálogo de eventos avisables, con su nombre humano.
     *
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        $options = [];
        foreach (SapNotifiableEvents::CATALOG as $event => $label) {
            $options[$event] = __($label);
        }

        return $options;
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            SapNotificationStatus::Pending->value => __('Pending'),
            SapNotificationStatus::Sent->value => __('Sent'),
            SapNotificationStatus::Failed->value => __('Failed'),
            SapNotificationStatus::Skipped->value => __('Skipped'),
            default => $status,
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            SapNotificationStatus::Sent->value => 'success',
            SapNotificationStatus::Failed->value => 'danger',
            SapNotificationStatus::Skipped->value => 'gray',
            default => 'warning',
        };
    }

    /**
     * @return array<string, class-string<Page>|PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSapNotifications::route('/'),
        ];
    }
}
