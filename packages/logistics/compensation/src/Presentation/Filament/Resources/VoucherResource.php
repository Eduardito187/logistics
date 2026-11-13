<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\Compensation\Application\Command\RevokeVoucher\RevokeVoucher;
use Logistics\Compensation\Domain\Event\VoucherIssued;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\CompensationCaseRecord;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\VoucherRecord;
use Logistics\Compensation\Presentation\Filament\Resources\VoucherResource\Pages\ListVouchers;
use Logistics\Compensation\Presentation\Filament\Resources\VoucherResource\Pages\ViewVoucher;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de los VALES emitidos (el crédito de un solo uso).
 *
 * Solo lectura sobre la fila: un vale se emite por el flujo de aprobación/reactores, nunca a mano. El
 * CÓDIGO se muestra SIEMPRE enmascarado (`DT-••••-7K2Q`): solo quien recibe el email ve el código completo.
 * Las mutaciones (anular, reenviar email) se despachan como comando/evento al núcleo, nunca escribiendo la
 * fila. Montos siempre legibles; nunca se muestra un UUID. Texto visible en inglés (regla 5).
 */
final class VoucherResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = VoucherRecord::class;

    /**
     * Estados del vale (valores crudos del dominio) → etiqueta legible en inglés.
     *
     * @var array<string, string>
     */
    private const STATUSES = [
        'issued' => 'Issued',
        'redeemed' => 'Redeemed',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ];

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-ticket';
    }

    public static function getNavigationSort(): int
    {
        return 61;
    }

    public static function getNavigationLabel(): string
    {
        return __('Vouchers');
    }

    public static function getModelLabel(): string
    {
        return __('voucher');
    }

    public static function getPluralModelLabel(): string
    {
        return __('vouchers');
    }

    /**
     * Título humano del registro: código ENMASCARADO + monto, nunca el UUID ni el código completo.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof VoucherRecord) {
            return null;
        }

        return self::maskCode($record->code).' · '.self::money($record->amount_cents, $record->currency);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    // Enmascarado SIEMPRE: el código completo solo viaja en el email al cliente.
                    ->formatStateUsing(static fn (string $state): string => self::maskCode($state)),
                TextColumn::make('amount_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(static fn (int $state, VoucherRecord $record): string => self::money($state, $record->currency))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'issued' => 'success',
                        'redeemed' => 'info',
                        'expired' => 'gray',
                        'revoked' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                TextColumn::make('magento_sync_status')
                    ->label(__('Magento sync'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'synced' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label(__('Expires at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('redeemed_at')
                    ->label(__('Redeemed at'))
                    ->dateTime()
                    ->placeholder(__('Not redeemed'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(static fn (): array => array_map(static fn (string $l): string => __($l), self::STATUSES)),
            ])
            ->recordActions([
                ViewAction::make(),
                // Anular (revoca) el vale. Solo antes del canje; el dominio rechaza anular uno ya canjeado.
                Action::make('revoke')
                    ->label(__('Revoke'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(static fn (VoucherRecord $record): bool => $record->status === 'issued')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->modalHeading(__('Revoke voucher'))
                    ->action(static function (VoucherRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new RevokeVoucher(
                            voucherId: (string) $record->getKey(),
                            actor: self::actor(),
                            reason: (string) $data['reason'],
                        ));

                        Notification::make()->title(__('Voucher revoked'))->success()->send();
                    }),
                // Reenviar el email del vale: re-encola el evento `voucher_issued` al outbox, que es el hecho
                // que consume notifications para enviar el correo (y, más adelante, el sync a Magento).
                Action::make('resendEmail')
                    ->label(__('Resend email'))
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalHeading(__('Resend voucher email'))
                    ->visible(static fn (VoucherRecord $record): bool => $record->status === 'issued'
                        && is_string($record->holder_email) && $record->holder_email !== '')
                    ->action(static function (VoucherRecord $record): void {
                        $case = CompensationCaseRecord::query()->find($record->case_id);

                        app(RecordDomainEvents::class)->record([
                            new VoucherIssued(
                                $record->id,
                                $record->case_id,
                                $record->code,
                                $record->amount_cents,
                                $record->currency,
                                $record->holder_email,
                                $record->expires_at->format(DATE_ATOM),
                                $case?->order_reference,
                                is_string($case?->trigger) ? $case->trigger : 'MANUAL',
                            ),
                        ], 'compensation.voucher');

                        Notification::make()->title(__('Voucher email queued'))->success()->send();
                    }),
            ])
            ->defaultSort('expires_at', 'asc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Voucher'))
                ->schema([
                    TextEntry::make('code')
                        ->label(__('Code'))
                        ->formatStateUsing(static fn (string $state): string => self::maskCode($state)),
                    TextEntry::make('amount_cents')
                        ->label(__('Amount'))
                        ->formatStateUsing(static fn (int $state, VoucherRecord $record): string => self::money($state, $record->currency)),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                    TextEntry::make('expires_at')
                        ->label(__('Expires at'))
                        ->dateTime(),
                    TextEntry::make('magento_sync_status')
                        ->label(__('Magento sync')),
                ])
                ->columns(2),
            Section::make(__('Redemption'))
                ->schema([
                    TextEntry::make('redeemed_at')
                        ->label(__('Redeemed at'))
                        ->dateTime()
                        ->placeholder(__('Not redeemed')),
                    TextEntry::make('redeemed_channel')
                        ->label(__('Redeemed channel'))
                        ->placeholder('—'),
                    TextEntry::make('redeemed_sale_ref')
                        ->label(__('Sale reference'))
                        ->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }

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
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'view' => ViewVoucher::route('/{record}'),
        ];
    }

    /**
     * Enmascara el código mostrando solo el último bloque (`DT-••••-7K2Q`), sin exponer el resto.
     */
    private static function maskCode(string $code): string
    {
        return 'DT-••••-'.substr($code, -4);
    }

    /**
     * Identidad del actor para la auditoría de la anulación: el id del usuario del panel como texto.
     */
    private static function actor(): string
    {
        $id = Filament::auth()->id();

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : 'admin';
    }

    /**
     * Formatea centavos como monto legible (nunca null/vacío): "Bs 157,00".
     */
    private static function money(int $cents, string $currency): string
    {
        $symbol = $currency === 'BOB' ? 'Bs' : $currency;

        return $symbol.' '.number_format($cents / 100, 2, ',', '.');
    }
}
