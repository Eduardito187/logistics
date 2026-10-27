<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
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
use Logistics\Inbound\Application\Command\CancelAsn\CancelAsn;
use Logistics\Inbound\Application\Command\CloseAsn\CloseAsn;
use Logistics\Inbound\Application\Command\LabelReceivedUnits\LabelReceivedUnits;
use Logistics\Inbound\Application\Command\RecordArrival\RecordArrival;
use Logistics\Inbound\Application\Command\RecordGoodsReceipt\RecordGoodsReceipt;
use Logistics\Inbound\Application\Command\ScheduleAsn\ScheduleAsn;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages\ListAsns;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages\ViewAsn;
use Logistics\Inbound\Presentation\Filament\Support\QcOutcomeOptions;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Admin de ASNs / citas de recepción de sellers (grupo Operations).
 *
 * Es una proyección de lectura sobre AsnRecord; toda MUTACIÓN de negocio (agendar, llegada, recepción,
 * etiquetado, cierre, cancelación) despacha un Command al CommandBus CQRS, nunca escribe el record directo
 * (respeta invariantes + outbox). Texto visible en inglés (regla 5); comentarios en español (regla 6). Sin
 * UUID visible: el seller se muestra por su nombre legal (lectura cross-context read-only, sin dep composer).
 */
final class AsnResource extends Resource
{
    use OfficeOnly;

    protected static ?string $model = AsnRecord::class;

    protected static string | \BackedEnum | null $navigationIcon = 'ds-inbox-arrow-down';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Inbound ASNs');
    }

    public static function getNavigationSort(): int
    {
        return 30;
    }

    public static function getModelLabel(): string
    {
        return __('ASN');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ASNs');
    }

    /**
     * Vista de detalle (solo lectura): seller, almacén, estado y ventana de cita.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('ASN'))
                ->schema([
                    TextEntry::make('seller_id')
                        ->label(__('Seller'))
                        ->formatStateUsing(static fn (string $state): string => self::sellerName($state)),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->color(static fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(static fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('appointment_date')
                        ->label(__('Appointment'))
                        ->placeholder(__('Not scheduled yet'))
                        ->formatStateUsing(static fn (?string $state, AsnRecord $record): string => $state === null
                            ? __('Not scheduled yet')
                            : $state.' '.(string) $record->appointment_start.'-'.(string) $record->appointment_end),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static function (Builder $query): Builder {
                /** @var Builder<AsnRecord> $query */
                return $query->with('lines');
            })
            ->columns([
                TextColumn::make('seller_id')
                    ->label(__('Seller'))
                    ->formatStateUsing(static fn (string $state): string => self::sellerName($state))
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(static fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('appointment_date')
                    ->label(__('Appointment'))
                    ->placeholder('—')
                    ->formatStateUsing(static fn (?string $state, AsnRecord $record): string => $state === null
                        ? '—'
                        : $state.' '.(string) $record->appointment_start.'-'.(string) $record->appointment_end),
                TextColumn::make('lines_count')
                    ->label(__('Lines'))
                    ->counts('lines'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(self::statusOptions())
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make(),
                self::scheduleAction(),
                self::arrivalAction(),
                self::receiptAction(),
                self::labelAction(),
                self::closeAction(),
                self::cancelAction(),
            ]);
    }

    /**
     * Agendar: despacha ScheduleAsn (reserva el cupo de andén). Visible mientras no haya llegado.
     */
    private static function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label(__('Schedule'))
            ->icon('ds-calendar-days')
            ->modalHeading(__('Schedule appointment'))
            ->modalSubmitActionLabel(__('Schedule'))
            ->visible(static fn (AsnRecord $record): bool => in_array($record->status, [
                AsnStatus::Announced->value,
                AsnStatus::Scheduled->value,
            ], true))
            ->schema([
                TextInput::make('appointment_date')
                    ->label(__('Date'))
                    ->type('date')
                    ->required(),
                TextInput::make('start_time')
                    ->label(__('Start time'))
                    ->type('time')
                    ->required(),
                TextInput::make('end_time')
                    ->label(__('End time'))
                    ->type('time')
                    ->required(),
            ])
            ->action(static function (AsnRecord $record, array $data): void {
                self::dispatch(new ScheduleAsn(
                    asnId: (string) $record->getKey(),
                    appointmentDate: (string) $data['appointment_date'],
                    startTime: substr((string) $data['start_time'], 0, 5),
                    endTime: substr((string) $data['end_time'], 0, 5),
                ), __('Appointment scheduled'));
            });
    }

    /**
     * Registrar llegada: despacha RecordArrival. Visible solo si está agendado.
     */
    private static function arrivalAction(): Action
    {
        return Action::make('arrival')
            ->label(__('Record arrival'))
            ->icon('ds-truck')
            ->color('info')
            ->modalHeading(__('Record arrival'))
            ->modalSubmitActionLabel(__('Record arrival'))
            ->visible(static fn (AsnRecord $record): bool => $record->status === AsnStatus::Scheduled->value)
            ->schema([
                TextInput::make('carrier_reference')
                    ->label(__('Carrier reference'))
                    ->maxLength(128),
            ])
            ->action(static function (AsnRecord $record, array $data): void {
                $carrier = isset($data['carrier_reference']) && $data['carrier_reference'] !== ''
                    ? (string) $data['carrier_reference']
                    : null;

                self::dispatch(new RecordArrival(
                    asnId: (string) $record->getKey(),
                    carrierReference: $carrier,
                ), __('Arrival recorded'));
            });
    }

    /**
     * Registrar recepción con QC por línea: despacha RecordGoodsReceipt. Visible si llegó (o recibiendo).
     */
    private static function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label(__('Record receipt'))
            ->icon('ds-clipboard-document-check')
            ->color('warning')
            ->modalHeading(__('Record goods receipt'))
            ->modalSubmitActionLabel(__('Record receipt'))
            ->visible(static fn (AsnRecord $record): bool => in_array($record->status, [
                AsnStatus::Arrived->value,
                AsnStatus::Receiving->value,
            ], true))
            ->fillForm(static fn (AsnRecord $record): array => [
                'lines' => $record->lines
                    ->map(static fn ($line): array => [
                        'sku' => $line->sku,
                        'accepted' => $line->expected_quantity,
                        'rejected' => 0,
                        'damaged' => 0,
                        'qc_outcome' => 'accepted',
                    ])
                    ->all(),
            ])
            ->schema([
                Repeater::make('lines')
                    ->label(__('Received lines'))
                    ->schema([
                        TextInput::make('sku')
                            ->label(__('SKU'))
                            ->required()
                            ->maxLength(128),
                        TextInput::make('accepted')
                            ->label(__('Accepted'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('rejected')
                            ->label(__('Rejected'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0),
                        TextInput::make('damaged')
                            ->label(__('Damaged'))
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(0),
                        Select::make('qc_outcome')
                            ->label(__('QC outcome'))
                            ->options(QcOutcomeOptions::all())
                            ->native(false)
                            ->required(),
                    ])
                    ->minItems(1),
            ])
            ->action(static function (AsnRecord $record, array $data): void {
                /** @var list<array{sku: string, accepted: int, rejected: int, damaged: int, qc_outcome: string}> $lines */
                $lines = [];
                foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $lines[] = [
                        'sku' => (string) ($line['sku'] ?? ''),
                        'accepted' => (int) ($line['accepted'] ?? 0),
                        'rejected' => (int) ($line['rejected'] ?? 0),
                        'damaged' => (int) ($line['damaged'] ?? 0),
                        'qc_outcome' => (string) ($line['qc_outcome'] ?? 'accepted'),
                    ];
                }

                self::dispatch(new RecordGoodsReceipt(
                    asnId: (string) $record->getKey(),
                    lines: $lines,
                ), __('Goods receipt recorded'));
            });
    }

    /**
     * Etiquetar unidades aceptadas: despacha LabelReceivedUnits. Visible si está recibido.
     */
    private static function labelAction(): Action
    {
        return Action::make('label')
            ->label(__('Label units'))
            ->icon('ds-qr-code')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Label received units'))
            ->modalDescription(__('Create the labeled units for the accepted quantities (convergence point).'))
            ->visible(static fn (AsnRecord $record): bool => $record->status === AsnStatus::Received->value)
            ->action(static function (AsnRecord $record): void {
                self::dispatch(new LabelReceivedUnits(
                    asnId: (string) $record->getKey(),
                ), __('Units labeled'));
            });
    }

    /**
     * Cerrar ASN: despacha CloseAsn. Visible si está recibido.
     */
    private static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Close'))
            ->icon('ds-check-circle')
            ->requiresConfirmation()
            ->modalHeading(__('Close ASN'))
            ->visible(static fn (AsnRecord $record): bool => $record->status === AsnStatus::Received->value)
            ->action(static function (AsnRecord $record): void {
                self::dispatch(new CloseAsn(
                    asnId: (string) $record->getKey(),
                ), __('ASN closed'));
            });
    }

    /**
     * Cancelar ASN: despacha CancelAsn. Visible mientras no sea terminal.
     */
    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel'))
            ->icon('ds-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel ASN'))
            ->visible(static fn (AsnRecord $record): bool => !in_array($record->status, [
                AsnStatus::Closed->value,
                AsnStatus::Cancelled->value,
            ], true))
            ->schema([
                TextInput::make('reason')
                    ->label(__('Reason'))
                    ->maxLength(255),
            ])
            ->action(static function (AsnRecord $record, array $data): void {
                $reason = isset($data['reason']) && $data['reason'] !== '' ? (string) $data['reason'] : null;

                self::dispatch(new CancelAsn(
                    asnId: (string) $record->getKey(),
                    reason: $reason,
                ), __('ASN cancelled'));
            });
    }

    /**
     * Despacha un comando por el CommandBus y notifica el resultado (éxito o error de dominio).
     */
    private static function dispatch(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);

            Notification::make()->title($successTitle)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Could not complete the action'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Nombre legal del seller a partir de su id (lectura read-only de la tabla sellers, sin dep composer).
     * Cae a una etiqueta legible si el seller no está (entorno sin sellers sembrados); nunca muestra el UUID.
     */
    private static function sellerName(string $sellerId): string
    {
        $name = DB::table('sellers')->where('id', $sellerId)->value('legal_name');

        return is_string($name) && $name !== '' ? $name : __('Unknown seller');
    }

    private static function statusLabel(string $state): string
    {
        return self::statusOptions()[$state] ?? $state;
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            AsnStatus::Received->value, AsnStatus::Closed->value => 'success',
            AsnStatus::Arrived->value, AsnStatus::Receiving->value => 'info',
            AsnStatus::Scheduled->value, AsnStatus::Announced->value => 'warning',
            AsnStatus::Cancelled->value => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (AsnStatus::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAsns::route('/'),
            'view' => ViewAsn::route('/{record}'),
        ];
    }
}
