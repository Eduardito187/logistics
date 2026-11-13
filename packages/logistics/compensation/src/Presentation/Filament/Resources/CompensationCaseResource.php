<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Logistics\Compensation\Application\Command\ApproveCompensationCase\ApproveCompensationCase;
use Logistics\Compensation\Application\Command\RejectCompensationCase\RejectCompensationCase;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\CompensationCaseRecord;
use Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource\Pages\ListCompensationCases;
use Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource\Pages\ViewCompensationCase;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de los CASOS de compensación (el problema y la decisión).
 *
 * Filament lista sobre la proyección Eloquent (`CompensationCaseRecord`), pero TODA decisión (aprobar,
 * aprobar con monto menor, rechazar) se despacha como comando al CommandBus CQRS — nunca se escribe la
 * fila directamente. El dominio custodia las invariantes: cuatro ojos (quien propone no decide) y override
 * SOLO a la baja. Las acciones de decisión se ocultan si el caso no está propuesto o si el actor es el
 * proponente.
 *
 * Texto visible en inglés (regla 5); comentarios en español (regla 6); nunca se muestra un UUID; los montos
 * siempre legibles (nunca null/vacío); las líneas afectadas llevan nombre + SKU (nunca "bulto").
 */
final class CompensationCaseResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = CompensationCaseRecord::class;

    /**
     * Disparadores (valores crudos del dominio) → etiqueta legible en inglés.
     *
     * @var array<string, string>
     */
    private const TRIGGERS = [
        'DELIVERY_FAILED' => 'Delivery failed',
        'LATE_DELIVERY' => 'Late delivery',
        'DAMAGED_PRODUCT' => 'Damaged product',
        'INCOMPLETE' => 'Incomplete',
        'LOW_RATING' => 'Low rating',
        'MANUAL' => 'Manual',
    ];

    /**
     * Estados del caso (valores crudos del dominio) → etiqueta legible en inglés.
     *
     * @var array<string, string>
     */
    private const STATUSES = [
        'proposed' => 'Proposed',
        'approved' => 'Approved',
        'issued' => 'Issued',
        'rejected' => 'Rejected',
        'not_compensable' => 'Not compensable',
    ];

    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-lifebuoy';
    }

    public static function getNavigationSort(): int
    {
        return 60;
    }

    public static function getNavigationLabel(): string
    {
        return __('Compensation cases');
    }

    public static function getModelLabel(): string
    {
        return __('compensation case');
    }

    public static function getPluralModelLabel(): string
    {
        return __('compensation cases');
    }

    /**
     * Título humano del registro: disparador + referencia de orden, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof CompensationCaseRecord) {
            return null;
        }

        $trigger = __(self::TRIGGERS[$record->trigger] ?? $record->trigger);
        $reference = $record->order_reference ?? __('No order reference');

        return "{$trigger} · {$reference}";
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trigger')
                    ->label(__('Trigger'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __(self::TRIGGERS[$state] ?? $state)),
                TextColumn::make('scope')
                    ->label(__('Scope'))
                    ->formatStateUsing(static fn (string $state): string => __(ucfirst(strtolower($state)))),
                TextColumn::make('order_reference')
                    ->label(__('Order'))
                    ->searchable()
                    ->placeholder(__('No order reference')),
                TextColumn::make('effective_cents')
                    ->label(__('Effective amount'))
                    ->formatStateUsing(static fn (int $state, CompensationCaseRecord $record): string => self::money($state, $record->currency))
                    ->sortable(),
                TextColumn::make('approval_level')
                    ->label(__('Approval level'))
                    ->badge(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'proposed' => 'warning',
                        'approved' => 'info',
                        'issued' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                TextColumn::make('proposed_by')
                    ->label(__('Proposed by'))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Opened at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(static fn (): array => array_map(static fn (string $l): string => __($l), self::STATUSES)),
                SelectFilter::make('trigger')
                    ->label(__('Trigger'))
                    ->native(false)
                    ->options(static fn (): array => array_map(static fn (string $l): string => __($l), self::TRIGGERS)),
            ])
            ->recordActions([
                ViewAction::make(),
                // Aprobar al monto propuesto. Oculta si no está propuesto o si el actor es el proponente (cuatro ojos).
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Approve compensation case'))
                    ->visible(static fn (CompensationCaseRecord $record): bool => self::canDecide($record))
                    ->action(static function (CompensationCaseRecord $record): void {
                        app(CommandBus::class)->dispatch(new ApproveCompensationCase(
                            caseId: (string) $record->getKey(),
                            approver: self::actor(),
                        ));

                        Notification::make()->title(__('Compensation case approved'))->success()->send();
                    }),
                // Aprobar con un monto MENOR (override solo a la baja; el dominio lo verifica).
                Action::make('approveLower')
                    ->label(__('Approve lower amount'))
                    ->icon('heroicon-o-arrow-trending-down')
                    ->color('warning')
                    ->visible(static fn (CompensationCaseRecord $record): bool => self::canDecide($record))
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('New amount (BOB)'))
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(static fn (CompensationCaseRecord $record): float => $record->effective_cents / 100)
                            ->helperText(__('Can only lower the effective amount, never raise it.')),
                    ])
                    ->modalHeading(__('Approve with a lower amount'))
                    ->action(static function (CompensationCaseRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new ApproveCompensationCase(
                            caseId: (string) $record->getKey(),
                            approver: self::actor(),
                            overrideCents: (int) round(((float) $data['amount']) * 100),
                        ));

                        Notification::make()->title(__('Compensation case approved'))->success()->send();
                    }),
                // Rechazar con motivo. Mismas reglas de visibilidad (cuatro ojos).
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(static fn (CompensationCaseRecord $record): bool => self::canDecide($record))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->modalHeading(__('Reject compensation case'))
                    ->action(static function (CompensationCaseRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new RejectCompensationCase(
                            caseId: (string) $record->getKey(),
                            approver: self::actor(),
                            reason: (string) $data['reason'],
                        ));

                        Notification::make()->title(__('Compensation case rejected'))->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Case'))
                ->schema([
                    TextEntry::make('trigger')
                        ->label(__('Trigger'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::TRIGGERS[$state] ?? $state)),
                    TextEntry::make('scope')
                        ->label(__('Scope'))
                        ->formatStateUsing(static fn (string $state): string => __(ucfirst(strtolower($state)))),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                    TextEntry::make('order_reference')
                        ->label(__('Order'))
                        ->placeholder(__('No order reference')),
                    TextEntry::make('approval_level')
                        ->label(__('Approval level'))
                        ->badge(),
                ])
                ->columns(2),
            Section::make(__('Amounts'))
                ->schema([
                    TextEntry::make('nominal_cents')
                        ->label(__('Nominal amount'))
                        ->formatStateUsing(static fn (int $state, CompensationCaseRecord $record): string => self::money($state, $record->currency)),
                    TextEntry::make('effective_cents')
                        ->label(__('Effective amount'))
                        ->formatStateUsing(static fn (int $state, CompensationCaseRecord $record): string => self::money($state, $record->currency)),
                    TextEntry::make('proposed_by')
                        ->label(__('Proposed by')),
                    TextEntry::make('approved_by')
                        ->label(__('Approved by'))
                        ->placeholder(__('Not decided yet')),
                    TextEntry::make('reason')
                        ->label(__('Reason'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Affected products'))
                ->schema([
                    RepeatableEntry::make('affected_lines')
                        ->label(__('Affected products'))
                        ->schema([
                            TextEntry::make('product_name')
                                ->label(__('Product'))
                                ->placeholder(__('Product')),
                            TextEntry::make('sku')
                                ->label(__('SKU'))
                                ->placeholder(__('Product')),
                            TextEntry::make('affected_quantity')
                                ->label(__('Affected quantity')),
                            TextEntry::make('unit_price_cents')
                                ->label(__('Unit price'))
                                ->formatStateUsing(static fn (int $state): string => self::money($state, 'BOB')),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    public static function canCreate(): bool
    {
        // El alta es por la puerta de integraciones (call center) o por los reactores del outbox, no a mano.
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCompensationCases::route('/'),
            'view' => ViewCompensationCase::route('/{record}'),
        ];
    }

    /**
     * ¿Puede el actor actual decidir este caso? Solo casos propuestos y nunca el propio proponente (cuatro ojos).
     */
    private static function canDecide(CompensationCaseRecord $record): bool
    {
        return $record->status === 'proposed' && $record->proposed_by !== self::actor();
    }

    /**
     * Identidad del aprobador/rechazador para la auditoría: el id del usuario del panel como texto.
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
