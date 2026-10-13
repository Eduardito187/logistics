<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Logistics\Carriers\Application\Command\ApproveSettlement\ApproveSettlement;
use Logistics\Carriers\Application\Command\AttachSettlementDocument\AttachSettlementDocument;
use Logistics\Carriers\Application\Command\DisputeSettlement\DisputeSettlement;
use Logistics\Carriers\Application\Command\MarkSettlementPaid\MarkSettlementPaid;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages\ListSettlements;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages\ViewSettlement;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Admin de liquidaciones de transportistas (P2-CAR-04).
 *
 * Nota de arquitectura: igual que los otros recursos, Filament lista sobre el record Eloquent
 * (proyección de lectura, SettlementRecord) y TODA mutación (aprobar, pagar, objetar, adjuntar
 * documento) se hace despachando comandos al CommandBus CQRS, nunca escribiendo el record directamente.
 * Las liquidaciones nacen por la puerta de integraciones (el transportista las envía), por eso el
 * recurso no define alta manual.
 *
 * Texto visible en inglés (regla 5 de CLAUDE.md); comentarios en español (regla 6).
 */
final class SettlementResource extends Resource
{
    protected static ?string $model = SettlementRecord::class;

    /**
     * Tipos de corredor válidos (mismos valores que CorridorRef del dominio, replicados como texto).
     *
     * @var array<string, string>
     */
    private const CORRIDOR_TYPES = [
        'cd_to_customer' => 'CD to customer',
        'cd_to_cd' => 'CD to CD',
        'cd_to_store' => 'CD to store',
        'store_to_store' => 'Store to store',
        'store_to_cd' => 'Store to CD',
        'store_pickup' => 'Store pickup',
    ];

    /**
     * Estados del ciclo de vida (mismos valores que SettlementStatus del dominio).
     *
     * @var array<string, string>
     */
    private const STATUSES = [
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'disputed' => 'Disputed',
    ];

    /**
     * Opciones de tipo de corredor con el label traducido (los VALUES siguen crudos).
     *
     * @return array<string, string>
     */
    private static function corridorTypeOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::CORRIDOR_TYPES);
    }

    /**
     * Opciones de estado con el label traducido (los VALUES siguen crudos).
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return array_map(static fn (string $label): string => __($label), self::STATUSES);
    }

    /**
     * Título humano del registro (para breadcrumbs y búsqueda global): transportista + período +
     * referencia, nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof SettlementRecord) {
            return null;
        }

        $carrier = $record->carrier->name ?? $record->carrier_id;

        return "{$carrier} · {$record->period} · {$record->reference}";
    }

    public static function getNavigationGroup(): string
    {
        return __('Carriers');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-document-currency-dollar';
    }

    public static function getModelLabel(): string
    {
        return __('Settlement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Settlements');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nombre real del transportista vía la relación Eloquent (sin N+1 gracias a getEloquentQuery).
                TextColumn::make('carrier.name')
                    ->label(__('Carrier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->searchable(),
                TextColumn::make('period')
                    ->label(__('Period'))
                    ->sortable(),
                TextColumn::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->formatStateUsing(static fn (string $state): string => __(self::CORRIDOR_TYPES[$state] ?? $state)),
                TextColumn::make('amount_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(static fn (int $state, SettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'submitted' => 'warning',
                        'approved' => 'info',
                        'paid' => 'success',
                        'disputed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
                SelectFilter::make('corridor_type')
                    ->label(__('Corridor type'))
                    ->options(self::corridorTypeOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                // Aprobar: despacha ApproveSettlement. Visible solo si está enviada u objetada.
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Approve settlement'))
                    ->visible(static fn (SettlementRecord $record): bool => in_array($record->status, ['submitted', 'disputed'], true))
                    ->action(static function (SettlementRecord $record): void {
                        app(CommandBus::class)->dispatch(new ApproveSettlement(
                            settlementId: (string) $record->getKey(),
                        ));

                        Notification::make()
                            ->title(__('Settlement approved'))
                            ->success()
                            ->send();
                    }),
                // Pagar: despacha MarkSettlementPaid. Visible solo si está aprobada.
                Action::make('markPaid')
                    ->label(__('Mark paid'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(static fn (SettlementRecord $record): bool => $record->status === 'approved')
                    ->schema([
                        TextInput::make('payment_reference')
                            ->label(__('Payment reference'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->modalHeading(__('Mark settlement as paid'))
                    ->action(static function (SettlementRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new MarkSettlementPaid(
                            settlementId: (string) $record->getKey(),
                            paymentReference: (string) $data['payment_reference'],
                        ));

                        Notification::make()
                            ->title(__('Settlement marked as paid'))
                            ->success()
                            ->send();
                    }),
                // Objetar: despacha DisputeSettlement. Visible mientras no esté pagada.
                Action::make('dispute')
                    ->label(__('Dispute'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->visible(static fn (SettlementRecord $record): bool => $record->status !== 'paid')
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->modalHeading(__('Dispute settlement'))
                    ->action(static function (SettlementRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new DisputeSettlement(
                            settlementId: (string) $record->getKey(),
                            reason: (string) $data['reason'],
                        ));

                        Notification::make()
                            ->title(__('Settlement disputed'))
                            ->success()
                            ->send();
                    }),
                // Adjuntar documento: despacha AttachSettlementDocument. Visible mientras no esté pagada.
                Action::make('attachDocument')
                    ->label(__('Attach document'))
                    ->icon('heroicon-o-paper-clip')
                    ->visible(static fn (SettlementRecord $record): bool => $record->status !== 'paid')
                    ->schema([
                        TextInput::make('type')
                            ->label(__('Type'))
                            ->required()
                            ->maxLength(64),
                        TextInput::make('reference')
                            ->label(__('Reference'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->modalHeading(__('Attach document'))
                    ->action(static function (SettlementRecord $record, array $data): void {
                        app(CommandBus::class)->dispatch(new AttachSettlementDocument(
                            settlementId: (string) $record->getKey(),
                            documentType: (string) $data['type'],
                            documentReference: (string) $data['reference'],
                        ));

                        Notification::make()
                            ->title(__('Document attached'))
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
                    TextEntry::make('carrier.name')
                        ->label(__('Carrier')),
                    TextEntry::make('reference')
                        ->label(__('Reference')),
                    TextEntry::make('period')
                        ->label(__('Period')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => match ($state) {
                            'submitted' => 'warning',
                            'approved' => 'info',
                            'paid' => 'success',
                            'disputed' => 'danger',
                            default => 'gray',
                        })
                        ->formatStateUsing(static fn (string $state): string => __(self::STATUSES[$state] ?? $state)),
                ])
                ->columns(2),
            Section::make(__('Corridor'))
                ->schema([
                    TextEntry::make('corridor_from')
                        ->label(__('Origin')),
                    TextEntry::make('corridor_to')
                        ->label(__('Destination')),
                    TextEntry::make('corridor_type')
                        ->label(__('Corridor type'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => __(self::CORRIDOR_TYPES[$state] ?? $state)),
                ])
                ->columns(3),
            Section::make(__('Amount'))
                ->schema([
                    TextEntry::make('amount_cents')
                        ->label(__('Amount'))
                        ->formatStateUsing(static fn (int $state, SettlementRecord $record): string => number_format($state / 100, 2).' '.$record->currency),
                    TextEntry::make('currency')
                        ->label(__('Currency')),
                    TextEntry::make('payment_reference')
                        ->label(__('Payment reference'))
                        ->placeholder(__('Not paid yet')),
                    TextEntry::make('dispute_reason')
                        ->label(__('Dispute reason'))
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make(__('Documents'))
                ->schema([
                    RepeatableEntry::make('documents')
                        ->label(__('Documents'))
                        ->schema([
                            TextEntry::make('type')
                                ->label(__('Type')),
                            TextEntry::make('reference')
                                ->label(__('Reference')),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    /**
     * Eager-load del transportista para que la columna `carrier.name` no dispare una consulta por fila.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('carrier');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSettlements::route('/'),
            'view' => ViewSettlement::route('/{record}'),
        ];
    }
}
