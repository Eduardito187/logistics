<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Filament\Resources;

use App\Filament\Support\TableCsvExport;
use Commerce\Orders\Application\Command\CancelOrder\CancelOrder;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages\ListOrders;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages\ViewOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Logistics\Shared\Presentation\Filament\Concerns\PlainNavigationLabel;

/**
 * Admin de órdenes del frente comercial (OMS).
 *
 * Nota de arquitectura: Filament usa OrderRecord solo como proyección de lectura. La cancelación NO
 * escribe el record: despacha CancelOrder(source='manual') al bus CQRS, que corre el agregado y emite
 * `orders.order_cancelled` por outbox (reembolso/retorno/liberación de cupo aguas abajo).
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6). El id (UUID) nunca se muestra: la
 * orden se identifica por el nombre del cliente (instantánea de la dirección de entrega).
 */
final class OrderResource extends Resource
{
    use OfficeOnly;
    use PlainNavigationLabel;

    protected static ?string $model = OrderRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 10;
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    /**
     * Color del badge según el estado de la orden.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'paid' => 'success',
            'fulfilling' => 'info',
            'cancelled' => 'danger',
            'pending' => 'warning',
            default => 'gray',
        };
    }

    /**
     * Total de la orden en centavos, sumado de las líneas (no hay columna persistida).
     */
    private static function totalCents(OrderRecord $record): int
    {
        $total = 0;
        foreach ($record->lines as $line) {
            $total += $line['unit_price_cents'] * $line['quantity'];
        }

        return $total;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // El id (UUID) nunca se muestra: se identifica por el nombre del cliente (snapshot de ship_to).
                TextColumn::make('customer_name')
                    ->label(__('Customer'))
                    ->state(static fn (OrderRecord $record): string => $record->ship_to['customer_name'])
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->whereRaw("ship_to->>'customer_name' ILIKE ?", ['%'.$search.'%'])),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state)))
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->state(static fn (OrderRecord $record): string => number_format(self::totalCents($record) / 100, 2).' '.$record->currency)
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label(__('Placed at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options([
                        'pending' => __('Pending'),
                        'paid' => __('Paid'),
                        'fulfilling' => __('Fulfilling'),
                        'cancelled' => __('Cancelled'),
                    ]),
            ])
            ->recordUrl(static fn (OrderRecord $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                self::cancelAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::cancelBulkAction(),
                    TableCsvExport::bulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Acción de cancelar la orden: despacha CancelOrder(source='manual') al bus CQRS (no escribe el
     * record). Visible salvo si ya está cancelada. Reutilizable en la tabla y en la cabecera del hub.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel order'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel order'))
            ->modalDescription(__('The order will be cancelled. If it was already paid, a refund and return are triggered downstream.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(static fn (OrderRecord $record): bool => $record->status !== 'cancelled')
            ->action(static function (OrderRecord $record, array $data): void {
                $reason = is_string($data['reason'] ?? null) && $data['reason'] !== ''
                    ? (string) $data['reason']
                    : null;

                app(CommandBus::class)->dispatch(new CancelOrder(
                    orderId: (string) $record->getKey(),
                    reason: $reason,
                    source: 'manual',
                ));

                Notification::make()
                    ->title(__('Order cancelled'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Acción masiva: cancela las órdenes seleccionadas. No escribe el record; despacha un
     * CancelOrder(source='manual') por orden al bus CQRS (idempotente: una orden ya cancelada se omite).
     * Tolerante: reporta cuántas se cancelaron y cuántas fallaron.
     */
    private static function cancelBulkAction(): BulkAction
    {
        return BulkAction::make('cancelBulk')
            ->label(__('Cancel order'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel order'))
            ->modalDescription(__('The order will be cancelled. If it was already paid, a refund and return are triggered downstream.'))
            ->deselectRecordsAfterCompletion()
            ->accessSelectedRecords()
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(static function (Collection $records, array $data): void {
                $reason = is_string($data['reason'] ?? null) && $data['reason'] !== ''
                    ? (string) $data['reason']
                    : null;

                $bus = app(CommandBus::class);
                $cancelled = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (!$record instanceof OrderRecord) {
                        continue;
                    }

                    // Las ya canceladas se omiten (idempotencia a nivel UI; el agregado también la protege).
                    if ($record->status === 'cancelled') {
                        $skipped++;

                        continue;
                    }

                    $bus->dispatch(new CancelOrder(
                        orderId: (string) $record->getKey(),
                        reason: $reason,
                        source: 'manual',
                    ));
                    $cancelled++;
                }

                Notification::make()
                    ->title(__('Orders cancelled'))
                    ->body(__(':cancelled cancelled, :skipped skipped.', ['cancelled' => $cancelled, 'skipped' => $skipped]))
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
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
