<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Sellers\Application\Command\ActivateSeller\ActivateSeller;
use Logistics\Sellers\Application\Command\SetSettlementTerms\SetSettlementTerms;
use Logistics\Sellers\Application\Command\SuspendSeller\SuspendSeller;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\CreateSeller;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\ListSellers;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\ViewSeller;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de sellers / proveedores de marketplace.
 *
 * Nota de arquitectura: Filament se apoya en un modelo Eloquent para listar y mostrar; aquí se usa
 * SellerRecord como proyección de lectura. Toda MUTACIÓN de negocio (alta, activación, suspensión,
 * términos de liquidación) se hace despachando comandos al CommandBus CQRS, nunca escribiendo el record
 * directamente (respeta invariantes + outbox).
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6). Sin UUID visible:
 * el título del registro es el nombre legal del seller.
 */
final class SellerResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = SellerRecord::class;

    protected static ?string $recordTitleAttribute = 'legal_name';

    protected static string | \BackedEnum | null $navigationIcon = 'ds-building-storefront';

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
        return __('Seller');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sellers');
    }

    /**
     * Formulario de alta. Solo los campos que admite el comando RegisterSeller.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('legal_name')
                ->label(__('Legal name'))
                ->required()
                ->maxLength(255),
            TextInput::make('tax_id')
                ->label(__('Tax ID'))
                ->required()
                ->maxLength(64),
            TextInput::make('contact_name')
                ->label(__('Contact name'))
                ->required()
                ->maxLength(255),
            TextInput::make('contact_email')
                ->label(__('Contact email'))
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('contact_phone')
                ->label(__('Contact phone'))
                ->tel()
                ->maxLength(32),
        ]);
    }

    /**
     * Vista de detalle (solo lectura): datos maestros, contacto y términos de liquidación.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Seller'))
                ->schema([
                    TextEntry::make('legal_name')
                        ->label(__('Legal name')),
                    TextEntry::make('tax_id')
                        ->label(__('Tax ID')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
                ])
                ->columns(3),
            Section::make(__('Contact'))
                ->schema([
                    TextEntry::make('contact_name')
                        ->label(__('Contact name')),
                    TextEntry::make('contact_email')
                        ->label(__('Contact email')),
                    TextEntry::make('contact_phone')
                        ->label(__('Contact phone'))
                        ->placeholder('—'),
                ])
                ->columns(3),
            Section::make(__('Settlement terms'))
                ->schema([
                    TextEntry::make('settlementTerms.commission_basis_points')
                        ->label(__('Commission'))
                        ->placeholder(__('Not set yet'))
                        ->formatStateUsing(static fn (int | string | null $state): string => $state === null
                            ? __('Not set yet')
                            : number_format(((int) $state) / 100, 2).' %'),
                    TextEntry::make('settlementTerms.fixed_fee_cents')
                        ->label(__('Fixed fee'))
                        ->placeholder(__('Not set yet'))
                        ->formatStateUsing(static function (int | string | null $state, SellerRecord $record): string {
                            if ($state === null) {
                                return __('Not set yet');
                            }
                            // Se lee la moneda por el query builder (tipado nullable fiable), no por la relación.
                            $currency = DB::table('seller_settlement_terms')
                                ->where('seller_id', $record->getKey())
                                ->value('currency');

                            return number_format(((int) $state) / 100, 2).' '.(is_string($currency) ? $currency : 'BOB');
                        }),
                    TextEntry::make('settlementTerms.payment_cycle')
                        ->label(__('Payment cycle'))
                        ->placeholder(__('Not set yet'))
                        ->formatStateUsing(static fn (?string $state): string => $state === null
                            ? __('Not set yet')
                            : __(ucfirst($state))),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static function (Builder $query): Builder {
                /** @var Builder<SellerRecord> $query */
                return $query->with('settlementTerms');
            })
            ->columns([
                TextColumn::make('legal_name')
                    ->label(__('Legal name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tax_id')
                    ->label(__('Tax ID'))
                    ->searchable(),
                TextColumn::make('contact_email')
                    ->label(__('Contact email'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst($state))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'onboarding' => __('Onboarding'),
                        'active' => __('Active'),
                        'suspended' => __('Suspended'),
                    ])
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Activar: despacha ActivateSeller al bus. Visible si no está activo.
                Action::make('activate')
                    ->label(__('Activate'))
                    ->icon('ds-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Activate seller'))
                    ->modalDescription(__('The seller will be enabled to operate.'))
                    ->visible(static fn (SellerRecord $record): bool => $record->status !== 'active')
                    ->action(static function (SellerRecord $record): void {
                        app(CommandBus::class)->dispatch(new ActivateSeller(
                            sellerId: (string) $record->getKey(),
                        ));

                        Notification::make()->title(__('Seller activated'))->success()->send();
                    }),
                // Suspender: despacha SuspendSeller al bus. Visible si no está suspendido.
                Action::make('suspend')
                    ->label(__('Suspend'))
                    ->icon('ds-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Suspend seller'))
                    ->modalDescription(__('The seller will be blocked until reactivated.'))
                    ->visible(static fn (SellerRecord $record): bool => $record->status !== 'suspended')
                    ->action(static function (SellerRecord $record): void {
                        app(CommandBus::class)->dispatch(new SuspendSeller(
                            sellerId: (string) $record->getKey(),
                        ));

                        Notification::make()->title(__('Seller suspended'))->success()->send();
                    }),
                // Editar términos de liquidación: despacha SetSettlementTerms al bus.
                Action::make('settlementTerms')
                    ->label(__('Settlement terms'))
                    ->icon('ds-banknotes')
                    ->modalHeading(__('Set settlement terms'))
                    ->modalSubmitActionLabel(__('Save terms'))
                    ->fillForm(static function (SellerRecord $record): array {
                        // Un seller puede no tener términos aún: query builder (stdClass|null fiable).
                        $terms = DB::table('seller_settlement_terms')
                            ->where('seller_id', $record->getKey())
                            ->first();
                        $currency = $terms?->currency;
                        $cycle = $terms?->payment_cycle;

                        return [
                            'commission_basis_points' => $terms?->commission_basis_points,
                            'fixed_fee_cents' => $terms?->fixed_fee_cents,
                            'currency' => is_string($currency) ? $currency : 'BOB',
                            'payment_cycle' => is_string($cycle) ? $cycle : 'monthly',
                        ];
                    })
                    ->schema([
                        TextInput::make('commission_basis_points')
                            ->label(__('Commission (basis points)'))
                            ->helperText(__('1500 basis points = 15%'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(10000)
                            ->required(),
                        TextInput::make('fixed_fee_cents')
                            ->label(__('Fixed fee (cents)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('currency')
                            ->label(__('Currency'))
                            ->default('BOB')
                            ->required()
                            ->maxLength(3),
                        Select::make('payment_cycle')
                            ->label(__('Payment cycle'))
                            ->options([
                                'weekly' => __('Weekly'),
                                'biweekly' => __('Biweekly'),
                                'monthly' => __('Monthly'),
                            ])
                            ->native(false)
                            ->required(),
                    ])
                    ->action(static function (SellerRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new SetSettlementTerms(
                            sellerId: (string) $record->getKey(),
                            commissionBasisPoints: (int) $data['commission_basis_points'],
                            fixedFeeCents: (int) $data['fixed_fee_cents'],
                            currency: (string) $data['currency'],
                            paymentCycle: (string) $data['payment_cycle'],
                        ));

                        Notification::make()->title(__('Settlement terms saved'))->success()->send();
                    }),
            ]);
    }

    /**
     * Color del badge de estado del seller.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'suspended' => 'danger',
            'onboarding' => 'warning',
            default => 'gray',
        };
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSellers::route('/'),
            'create' => CreateSeller::route('/create'),
            'view' => ViewSeller::route('/{record}'),
        ];
    }
}
