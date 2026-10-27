<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Logistics\Settlement\Application\Command\ConfirmSettlement\ConfirmSettlement;
use Logistics\Settlement\Application\Command\DraftSellerSettlement\DraftSellerSettlement;
use Logistics\Settlement\Application\Command\MarkSettlementPaid\MarkSettlementPaid;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages\ListSellerSettlements;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages\ViewSellerSettlement;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Admin de liquidaciones de sellers (marketplace, Fase 4).
 *
 * Nota de arquitectura: Filament lista sobre el record Eloquent (proyección de lectura) y TODA mutación
 * (armar borrador, confirmar, marcar pagada) se hace despachando comandos al CommandBus CQRS, nunca
 * escribiendo el record directamente. El nombre del seller se resuelve cross-context (tabla `sellers`,
 * solo lectura) para no mostrar UUIDs; el UUID crudo queda en una columna oculta.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class SellerSettlementResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = SellerSettlementRecord::class;

    /**
     * Estados del ciclo de vida (mismos valores que SettlementStatus del dominio).
     *
     * @var array<string, string>
     */
    private const STATUSES = [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'paid' => 'Paid',
    ];

    /**
     * Cache por request de nombres de seller (id → nombre) para no repetir la consulta cross-context.
     *
     * @var array<string, string|null>
     */
    private static array $sellerNameCache = [];

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationSort(): int
    {
        return 25;
    }

    public static function getNavigationIcon(): string
    {
        return 'ds-banknotes';
    }

    public static function getModelLabel(): string
    {
        return __('Seller settlement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Seller settlements');
    }

    /**
     * Título humano del registro: seller + periodo, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof SellerSettlementRecord) {
            return null;
        }

        $seller = self::sellerName($record->seller_id) ?? '—';

        return "{$seller} · {$record->period_from} — {$record->period_to}";
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::STATUSES);
    }

    /**
     * Nombre legible de un seller (tabla `sellers`, cross-context read-only, memoizado por request).
     */
    private static function sellerName(string $sellerId): ?string
    {
        if (array_key_exists($sellerId, self::$sellerNameCache)) {
            return self::$sellerNameCache[$sellerId];
        }

        try {
            $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');
        } catch (Throwable) {
            $name = null;
        }

        return self::$sellerNameCache[$sellerId] = is_string($name) ? $name : null;
    }

    /**
     * Opciones de seller (id → nombre) para el select buscable de "Draft settlement".
     *
     * @return array<string, string>
     */
    private static function sellerOptions(): array
    {
        try {
            /** @var array<string, string> $options */
            $options = DB::table('sellers')->orderBy('legal_name')->pluck('legal_name', 'id')->all();
        } catch (Throwable) {
            return [];
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('seller_id')
                    ->label(__('Seller'))
                    ->formatStateUsing(static fn (string $state): string => self::sellerName($state) ?? __('Unknown seller'))
                    ->searchable(false),
                TextColumn::make('period_from')
                    ->label(__('Period'))
                    ->formatStateUsing(static fn (string $state, SellerSettlementRecord $record): string => "{$state} — {$record->period_to}")
                    ->sortable(),
                TextColumn::make('gross_cents')
                    ->label(__('Gross'))
                    ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency)
                    ->sortable(),
                TextColumn::make('net_cents')
                    ->label(__('Net payable'))
                    ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'confirmed' => 'info',
                        'paid' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                // Referencia legible (seller · periodo) en vez del UUID de la PK; sortable por el id real.
                TextColumn::make('id')
                    ->label(__('Reference'))
                    ->state(static fn (SellerSettlementRecord $record): string => self::getRecordTitle($record) ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->headerActions([
                // Armar borrador: despacha DraftSellerSettlement agregando los despachos del seller del periodo.
                CreateAction::make('draft')
                    ->label(__('Draft settlement'))
                    ->icon('ds-document-plus')
                    ->modalHeading(__('Draft seller settlement'))
                    ->createAnother(false)
                    ->schema([
                        Select::make('seller_id')
                            ->label(__('Seller'))
                            ->options(self::sellerOptions())
                            ->searchable()
                            ->native(false)
                            ->required(),
                        DatePicker::make('period_from')
                            ->label(__('Period from'))
                            ->native(false)
                            ->required(),
                        DatePicker::make('period_to')
                            ->label(__('Period to'))
                            ->native(false)
                            ->required(),
                        TextInput::make('commission_basis_points')
                            ->label(__('Commission (basis points)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->required(),
                        TextInput::make('fixed_fee_cents')
                            ->label(__('Fixed fee per dispatch (cents)'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])
                    ->using(static function (array $data): ?Model {
                        app(CommandBus::class)->dispatch(new DraftSellerSettlement(
                            sellerId: (string) $data['seller_id'],
                            periodFrom: (string) $data['period_from'],
                            periodTo: (string) $data['period_to'],
                            commissionBasisPoints: (int) $data['commission_basis_points'],
                            fixedFeeCents: (int) $data['fixed_fee_cents'],
                        ));

                        Notification::make()
                            ->title(__('Settlement drafted'))
                            ->success()
                            ->send();

                        // El comando persiste vía repositorio; no devolvemos un record para Filament.
                        return null;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                // Confirmar: despacha ConfirmSettlement. Visible solo si está en borrador.
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon('ds-check-circle')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('Confirm settlement'))
                    ->visible(static fn (SellerSettlementRecord $record): bool => $record->status === 'draft')
                    ->action(static function (SellerSettlementRecord $record): void {
                        app(CommandBus::class)->dispatch(new ConfirmSettlement(
                            settlementId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Settlement confirmed'))
                            ->success()
                            ->send();
                    }),
                // Marcar pagada: despacha MarkSettlementPaid. Visible solo si está confirmada.
                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon('ds-banknotes')
                    ->color('success')
                    ->visible(static fn (SellerSettlementRecord $record): bool => $record->status === 'confirmed')
                    ->schema([
                        TextInput::make('payment_reference')
                            ->label(__('Payment reference'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->modalHeading(__('Mark settlement as paid'))
                    ->action(static function (SellerSettlementRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new MarkSettlementPaid(
                            settlementId: (string) $record->getKey(),
                            paymentReference: (string) $data['payment_reference'],
                        ));

                        Notification::make()
                            ->title(__('Settlement marked as paid'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Vista de detalle de una liquidación (solo lectura).
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Settlement'))
                ->schema([
                    TextEntry::make('seller_id')
                        ->label(__('Seller'))
                        ->formatStateUsing(static fn (string $state): string => self::sellerName($state) ?? __('Unknown seller')),
                    TextEntry::make('period_from')
                        ->label(__('Period'))
                        ->formatStateUsing(static fn (string $state, SellerSettlementRecord $record): string => "{$state} — {$record->period_to}"),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => match ($state) {
                            'draft' => 'warning',
                            'confirmed' => 'info',
                            'paid' => 'success',
                            default => 'gray',
                        })
                        ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                    TextEntry::make('payment_reference')
                        ->label(__('Payment reference'))
                        ->placeholder(__('Not paid yet')),
                ])
                ->columns(2),
            Section::make(__('Amounts'))
                ->schema([
                    TextEntry::make('gross_cents')
                        ->label(__('Gross'))
                        ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('commission_cents')
                        ->label(__('Commission'))
                        ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('fixed_fee_cents')
                        ->label(__('Fixed fee'))
                        ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('net_cents')
                        ->label(__('Net payable'))
                        ->formatStateUsing(static fn (int $state, SellerSettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                ])
                ->columns(2),
            Section::make(__('Dispatches'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label(__('Dispatches'))
                        ->schema([
                            TextEntry::make('dispatch_reference')
                                ->label(__('Dispatch reference')),
                            TextEntry::make('base_amount_cents')
                                ->label(__('Base amount'))
                                ->formatStateUsing(static fn (int $state): string => number_format($state / 100, 2)),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSellerSettlements::route('/'),
            'view' => ViewSellerSettlement::route('/{record}'),
        ];
    }
}
