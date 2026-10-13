<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Application\Command\QuotePromise\QuotePromise;
use Logistics\Promise\Application\Command\ReservePromise\ReservePromise;
use Logistics\Promise\Application\Query\ListAvailableSlots\ListAvailableSlots;
use Logistics\Promise\Application\ReadModel\AvailableSlotView;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Admin de las promesas de entrega y de su reserva temporal de cupo.
 *
 * Las promesas NO se crean ni editan como un CRUD: avanzan por su ciclo de vida de negocio
 * (cotizar → reservar → confirmar / expirar) y cada transición se dispara despachando el Command
 * real por el CommandBus. Así se respeta Presentation → Application (invariantes del agregado +
 * transacción + outbox) y nunca se escribe el Record a mano para una operación de negocio.
 *
 * La "reserva de cupo" no es una tabla aparte: es el estado RESERVED más la ventana `reserved_until`
 * de la propia promesa, por eso este Resource cubre ambas entidades administrables.
 *
 * Datos legibles: el registro se titula por `shipment_ref` (nunca el UUID); el estado y la franja se
 * muestran con etiquetas humanas y badges de color, y la ventana de reserva como fecha/hora. La columna
 * `zone_id` es un UUID que pertenece al contexto `coverage`: este contexto sigue DESACOPLADO de coverage
 * a nivel de dominio (solo conoce la zona como string primitivo y no la tiene como dependencia composer),
 * por eso NO se agrega una relación Eloquent cruzada. Para no mostrar un UUID crudo, el nombre de la zona
 * se resuelve de solo lectura con zoneName() (lookup puntual a ZoneRecord) y se expone como columna/campo
 * `Zone`; el UUID queda como `Zone reference (ID)` secundario. Todo el texto visible va en inglés (regla 5
 * de CLAUDE.md).
 *
 * Para Filament v4. El integrador debe exponer esta ruta de descubrimiento en el AdminPanelProvider:
 *   ->discoverResources(in: base_path('packages/logistics/promise/src/Presentation/Filament/Resources'),
 *                        for: 'Logistics\\Promise\\Presentation\\Filament\\Resources')
 */
final class PromiseResource extends Resource
{
    protected static ?string $model = PromiseRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $recordTitleAttribute = 'shipment_ref';

    /**
     * Grupo de navegación. Se define por método (no por propiedad) para que el texto visible pase por
     * __() y sea traducible; el string fuente queda en inglés como clave del diccionario.
     */
    public static function getNavigationGroup(): string
    {
        return __('Promise');
    }

    public static function getNavigationLabel(): string
    {
        return __('Delivery promises');
    }

    public static function getModelLabel(): string
    {
        return __('Delivery promise');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Delivery promises');
    }

    /**
     * Etiquetas humanas de los estados de la promesa. Las claves son los valores crudos del enum
     * PromiseStatus (no se traducen); los textos visibles pasan por __() para ser traducibles.
     *
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        return [
            'QUOTED' => __('Quoted'),
            'RESERVED' => __('Reserved'),
            'CONFIRMED' => __('Confirmed'),
            'EXPIRED' => __('Expired'),
        ];
    }

    /**
     * Nombre legible de la zona (contexto coverage) a partir de su id. Promise está desacoplado de
     * coverage y solo guarda el id primitivo; para no mostrar un UUID crudo en el panel se resuelve
     * aquí el nombre de solo lectura desde ZoneRecord. Devuelve null si la zona no existe.
     */
    private static function zoneName(?string $zoneId): ?string
    {
        if ($zoneId === null || $zoneId === '') {
            return null;
        }

        $name = ZoneRecord::query()->whereKey($zoneId)->value('name');

        return $name === null ? null : (string) $name;
    }

    /**
     * TTL por defecto (minutos) de la ventana de reserva al reservar cupo desde el admin.
     */
    private const DEFAULT_RESERVATION_TTL = 15;

    /**
     * Las promesas no se crean con un formulario CRUD: nacen de QuotePromise (acción de cabecera).
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Contador de navegación: promesas con reserva de cupo vigente (estado RESERVED).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = PromiseRecord::query()->where('status', 'RESERVED')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('shipment_ref')
                ->label(__('Shipment reference')),
            TextEntry::make('status')
                ->label(__('Status'))
                ->badge()
                ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state)
                ->color(self::statusColor(...)),
            TextEntry::make('delivery_window')
                ->label(__('Delivery window'))
                ->state(static fn (PromiseRecord $record): string => self::deliveryWindow($record)),
            TextEntry::make('slot_date')
                ->label(__('Slot date'))
                ->date(),
            TextEntry::make('time_slot')
                ->label(__('Time slot'))
                ->badge()
                ->color('gray'),
            TextEntry::make('reserved_until')
                ->label(__('Reserved until'))
                ->dateTime()
                ->placeholder(__('Not reserved')),
            TextEntry::make('zone_name')
                ->label(__('Zone'))
                ->state(static fn (PromiseRecord $record): ?string => self::zoneName($record->zone_id))
                ->placeholder('—'),
            TextEntry::make('zone_id')
                ->label(__('Zone reference (ID)'))
                ->copyable()
                ->placeholder('—'),
            TextEntry::make('id')
                ->label(__('Promise ID'))
                ->copyable(),
            TextEntry::make('created_at')
                ->label(__('Created at'))
                ->dateTime()
                ->placeholder('—'),
            TextEntry::make('updated_at')
                ->label(__('Updated at'))
                ->dateTime()
                ->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment_ref')
                    ->label(__('Shipment'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('delivery_window')
                    ->label(__('Delivery window'))
                    ->state(static fn (PromiseRecord $record): string => self::deliveryWindow($record))
                    ->description(__('Promised date and slot')),
                TextColumn::make('time_slot')
                    ->label(__('Time slot'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statuses()[$state] ?? $state)
                    ->color(self::statusColor(...))
                    ->sortable(),
                IconColumn::make('reserved_active')
                    ->label(__('Reservation'))
                    ->boolean()
                    ->state(static fn (PromiseRecord $record): bool => $record->status === 'RESERVED')
                    ->tooltip(__('Slot reservation currently held')),
                TextColumn::make('reserved_until')
                    ->label(__('Reserved until'))
                    ->dateTime()
                    ->placeholder(__('Not reserved'))
                    ->sortable(),
                TextColumn::make('zone_name')
                    ->label(__('Zone'))
                    ->state(static fn (PromiseRecord $record): ?string => self::zoneName($record->zone_id))
                    ->placeholder('—'),
                TextColumn::make('zone_id')
                    ->label(__('Zone reference (ID)'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statuses()),
                TernaryFilter::make('reserved')
                    ->label(__('Active reservation'))
                    ->placeholder(__('All promises'))
                    ->trueLabel(__('With held slot (reserved)'))
                    ->falseLabel(__('Without held slot'))
                    ->queries(
                        true: static function (Builder $query): Builder {
                            /** @var Builder<PromiseRecord> $query */
                            return $query->where('status', 'RESERVED');
                        },
                        false: static function (Builder $query): Builder {
                            /** @var Builder<PromiseRecord> $query */
                            return $query->where('status', '!=', 'RESERVED');
                        },
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('slot_date')
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('Slot date from'))
                            ->native(false),
                        DatePicker::make('until')
                            ->label(__('Slot date until'))
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('slot_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('slot_date', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                self::reserveAction(),
                self::confirmAction(),
                self::expireAction(),
            ])
            ->defaultSort('slot_date', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromises::route('/'),
            'view' => Pages\ViewPromise::route('/{record}'),
        ];
    }

    /**
     * Flujo "Quote promise": cotiza una promesa para un envío mostrando primero las franjas con cupo
     * disponible (ListAvailableSlots) y, al elegir una, despacha QuotePromise por el CommandBus. Es la
     * acción de cabecera del listado (no hay alta CRUD).
     */
    public static function quoteAction(): Action
    {
        return Action::make('quote')
            ->label(__('Quote promise'))
            ->icon('heroicon-o-calculator')
            ->modalHeading(__('Quote a delivery promise'))
            ->modalDescription(__('Pick an available slot for the zone and date, then quote the promise.'))
            ->modalSubmitActionLabel(__('Quote'))
            ->schema([
                TextInput::make('shipment_ref')
                    ->label(__('Shipment reference'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('zone_id')
                    ->label(__('Zone reference'))
                    ->helperText(__('Coverage zone identifier provided by the channel.'))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(static fn (Set $set): mixed => $set('time_slot', null)),
                DatePicker::make('slot_date')
                    ->label(__('Slot date'))
                    ->native(false)
                    ->required()
                    ->minDate(today())
                    ->format('Y-m-d')
                    ->live()
                    ->afterStateUpdated(static fn (Set $set): mixed => $set('time_slot', null)),
                Select::make('time_slot')
                    ->label(__('Available time slot'))
                    ->helperText(__('Only slots with remaining capacity for the chosen zone and date are listed.'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(static fn (Get $get): array => self::availableTimeSlots(
                        self::stringOrNull($get('zone_id')),
                        self::stringOrNull($get('slot_date')),
                    )),
            ])
            ->action(function (array $data): void {
                /** @var array{shipment_ref: string, zone_id: string, slot_date: string, time_slot: string} $data */
                self::dispatch(
                    new QuotePromise(
                        $data['shipment_ref'],
                        $data['zone_id'],
                        $data['slot_date'],
                        $data['time_slot'],
                    ),
                    __('Promise quoted'),
                    __('The delivery promise was quoted and is now awaiting reservation.'),
                );
            });
    }

    /**
     * Flujo "Reserve slot": reserva temporalmente el cupo de una promesa cotizada por una ventana de
     * TTL minutos. Solo visible mientras la promesa está en QUOTED. Despacha ReservePromise.
     */
    public static function reserveAction(): Action
    {
        return Action::make('reserve')
            ->label(__('Reserve slot'))
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->visible(static fn (PromiseRecord $record): bool => $record->status === 'QUOTED')
            ->modalHeading(__('Reserve the slot'))
            ->modalDescription(__('Hold the capacity for this promise for a limited time window.'))
            ->modalSubmitActionLabel(__('Reserve'))
            ->schema([
                TextInput::make('ttl_minutes')
                    ->label(__('Hold window (minutes)'))
                    ->helperText(__('How long the slot stays reserved before it can expire.'))
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(self::DEFAULT_RESERVATION_TTL),
            ])
            ->action(function (PromiseRecord $record, array $data): void {
                /** @var array{ttl_minutes: int|string} $data */
                self::dispatch(
                    new ReservePromise((string) $record->getKey(), (int) $data['ttl_minutes']),
                    __('Slot reserved'),
                    __('The slot is now held for this promise.'),
                );
            });
    }

    /**
     * Flujo "Confirm promise": confirma una promesa reservada dentro de su ventana. Solo visible en
     * RESERVED. Despacha ConfirmPromise (el dominio rechaza si la ventana ya venció).
     */
    public static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label(__('Confirm promise'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(static fn (PromiseRecord $record): bool => $record->status === 'RESERVED')
            ->requiresConfirmation()
            ->modalHeading(__('Confirm the promise'))
            ->modalDescription(__('Confirm this reserved promise. This fails if the reservation window has already expired.'))
            ->modalSubmitActionLabel(__('Confirm'))
            ->action(function (PromiseRecord $record): void {
                self::dispatch(
                    new ConfirmPromise((string) $record->getKey()),
                    __('Promise confirmed'),
                    __('The delivery is now firmly promised.'),
                );
            });
    }

    /**
     * Flujo "Expire reservation": expira una reserva vencida y libera su cupo. Solo visible en RESERVED.
     * Despacha ExpireReservation.
     */
    public static function expireAction(): Action
    {
        return Action::make('expire')
            ->label(__('Expire reservation'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(static fn (PromiseRecord $record): bool => $record->status === 'RESERVED')
            ->requiresConfirmation()
            ->modalHeading(__('Expire the reservation'))
            ->modalDescription(__('Release the held slot for this promise. This cannot be undone.'))
            ->modalSubmitActionLabel(__('Expire'))
            ->action(function (PromiseRecord $record): void {
                self::dispatch(
                    new ExpireReservation((string) $record->getKey()),
                    __('Reservation expired'),
                    __('The held slot was released.'),
                );
            });
    }

    /**
     * Despacha un comando de negocio por el CommandBus y notifica el resultado. Un fallo de dominio se
     * reporta como notificación y detiene el flujo (Halt) para que el modal no se cierre en silencio.
     */
    private static function dispatch(Command $command, string $successTitle, string $successBody): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);
        } catch (PromiseException $exception) {
            Notification::make()
                ->title(__('Could not complete the operation'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        Notification::make()
            ->title($successTitle)
            ->body($successBody)
            ->success()
            ->send();
    }

    /**
     * Franjas con cupo disponible para una zona y fecha, como opciones select (etiqueta = etiqueta de
     * franja). Consulta el caso de uso ListAvailableSlots por el QueryBus; vacío si faltan datos o si la
     * consulta falla (p. ej. fecha inválida mientras se escribe).
     *
     * @return array<string, string>
     */
    private static function availableTimeSlots(?string $zoneId, ?string $slotDate): array
    {
        if ($zoneId === null || $zoneId === '' || $slotDate === null || $slotDate === '') {
            return [];
        }

        try {
            /** @var list<AvailableSlotView> $slots */
            $slots = app(QueryBus::class)->ask(new ListAvailableSlots($zoneId, $slotDate));
        } catch (PromiseException) {
            return [];
        }

        $options = [];
        foreach ($slots as $slot) {
            $options[$slot->timeSlot] = $slot->timeSlot;
        }

        return $options;
    }

    /**
     * Ventana de entrega legible: fecha de la franja + etiqueta de franja (p. ej. "2026-10-20 · AM").
     */
    private static function deliveryWindow(PromiseRecord $record): string
    {
        return trim($record->slot_date.' · '.$record->time_slot, ' ·');
    }

    /**
     * Normaliza un valor de formulario a string o null (los getters de Filament devuelven mixed).
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Color del badge de estado, coherente con el ciclo de vida de la promesa.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            'QUOTED' => 'gray',
            'RESERVED' => 'warning',
            'CONFIRMED' => 'success',
            'EXPIRED' => 'danger',
            default => 'gray',
        };
    }
}
