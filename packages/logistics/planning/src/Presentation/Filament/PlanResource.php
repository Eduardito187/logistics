<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Application\Command\AssignShipmentToVehicle\AssignShipmentToVehicle;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlan;
use Logistics\Planning\Application\Command\GeneratePlanProposal\GeneratePlanProposal;
use Logistics\Planning\Application\Command\GeneratePlanProposal\ProposalStopInput;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Application\Policy\RouteSpansMultipleDepartments;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRun;
use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Presentation\Filament\PlanResource\Pages;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Admin de los planes de reparto.
 *
 * El plan es un AGREGADO transaccional, no una fila editable: nace, se asignan envíos, el motor de rutas
 * propone secuencia y ETA, un humano reordena y finalmente lo publica. Por eso este resource NO expone
 * CRUD (crear/editar/borrar está deshabilitado) sino los CASOS DE USO del contexto como acciones que
 * despachan el comando real al CommandBus (dominio + outbox transaccional): generar la propuesta,
 * asignar un envío, reordenar paradas y publicar. Nunca se escribe el Record a mano para operaciones de
 * negocio.
 *
 * La tabla y la vista muestran datos humanos (día del plan, placa del camión, referencia del pedido) en
 * lugar de los UUID crudos, vía las relaciones Eloquent hacia Fleet y Shipments.
 *
 * Todo el texto visible va en inglés (regla 5 del proyecto). Comentarios en español.
 *
 * @extends resource<PlanRecord>
 */
final class PlanResource extends Resource
{
    protected static ?string $model = PlanRecord::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationSort(): int
    {
        return 10;
    }

    public static function getNavigationLabel(): string
    {
        return __('Delivery Plans');
    }

    public static function getModelLabel(): string
    {
        return __('delivery plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery plans');
    }

    /**
     * Título humano del registro (para cabeceras y búsqueda global): día del plan y placa del camión,
     * nunca el UUID.
     */
    public static function getRecordTitle(?Model $record): ?string
    {
        if (!$record instanceof PlanRecord) {
            return null;
        }

        $plate = $record->vehicle->plate ?? '—';

        return "{$record->plan_date} · {$plate}";
    }

    /**
     * Eager-load del camión y de las paradas con su envío para evitar N+1 al pintar placas y referencias.
     *
     * @return Builder<PlanRecord>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'stops.shipment']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan_date')
                    ->label(__('Plan date'))
                    ->date()
                    ->sortable(),
                // Placa del camión vía relación con Fleet (no el UUID vehicle_id).
                TextColumn::make('vehicle.plate')
                    ->label(__('Vehicle'))
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                    ->color(static fn (string $state): string => self::statusColor($state)),
                TextColumn::make('stops_count')
                    ->label(__('Stops'))
                    ->counts('stops')
                    ->alignCenter(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->native(false)
                    ->options(self::statusOptions()),
            ])
            ->recordActions([
                // Flujo: asignar un envío al camión (comando AssignShipmentToVehicle). Ajuste manual;
                // el envío entra al final y queda pendiente de reordenar o regenerar.
                Action::make('assignShipment')
                    ->label(__('Assign shipment'))
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading(__('Assign a shipment to this truck'))
                    ->modalSubmitActionLabel(__('Assign'))
                    ->visible(static fn (PlanRecord $record): bool => $record->status !== PlanStatus::Published->value)
                    ->schema([
                        self::shipmentSelect('shipmentId')
                            ->helperText(__('The shipment is added at the end of the route; reorder or regenerate afterwards.')),
                    ])
                    ->action(static function (PlanRecord $record, array $data, CommandBus $commandBus): void {
                        $shipmentId = (string) $data['shipmentId'];
                        // Foto legible del destino tomada del envío, para que viaje con la parada.
                        $shipment = ShipmentRecord::query()->find($shipmentId);

                        try {
                            $commandBus->dispatch(new AssignShipmentToVehicle(
                                planId: (string) $record->getKey(),
                                shipmentId: $shipmentId,
                                orderReference: $shipment?->external_id,
                                customerName: $shipment?->customer_name,
                                address: $shipment?->address_text,
                                // Coordenadas del envío a asignar y de las paradas de cliente que ya
                                // tiene el plan: alimentan la validación de la regla geográfica (una
                                // entrega a clientes no cruza departamentos). Las aporta el envío.
                                latitude: self::coordinate($shipment?->lat),
                                longitude: self::coordinate($shipment?->lng),
                                currentClientStops: self::clientStopsOf($record),
                            ));
                        } catch (RouteSpansMultipleDepartments) {
                            self::failureText(
                                __('Could not assign shipment'),
                                __('A delivery route to customers cannot cross departments. All customer stops on a truck must be in the same department (same municipality, or inter-municipal within the same department).'),
                            );

                            return;
                        } catch (PlanningException $exception) {
                            self::failure(__('Could not assign shipment'), $exception);

                            return;
                        }

                        self::success(__('Shipment assigned'), __('The shipment was added to the plan.'));
                    }),
                // Flujo: reordenar la secuencia de reparto (comando ReorderStops). El operador arrastra
                // las paradas; se envía el nuevo orden completo de envíos.
                Action::make('reorderStops')
                    ->label(__('Reorder stops'))
                    ->icon('heroicon-o-arrows-up-down')
                    ->modalHeading(__('Reorder the delivery sequence'))
                    ->modalDescription(__('Drag the stops into the desired delivery order, then save.'))
                    ->modalSubmitActionLabel(__('Save order'))
                    ->visible(static fn (PlanRecord $record): bool => $record->status !== PlanStatus::Published->value
                        && $record->stops()->exists())
                    ->fillForm(static fn (PlanRecord $record): array => [
                        'stops' => $record->stops()->with('shipment')->get()
                            ->map(static fn (PlannedStopRecord $stop): array => [
                                'shipment_id' => $stop->shipment_id,
                                'label' => self::shipmentLabel($stop->shipment)
                                    ?? '—',
                            ])
                            ->all(),
                    ])
                    ->schema([
                        Repeater::make('stops')
                            ->label(__('Stops in delivery order'))
                            ->reorderable()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderableWithButtons()
                            ->schema([
                                Hidden::make('shipment_id'),
                                // Etiqueta legible de solo lectura: no se envía (dehydrated(false)); el
                                // dato que viaja es shipment_id, y el orden lo da la posición de la fila.
                                TextInput::make('label')
                                    ->label(__('Shipment'))
                                    ->disabled()
                                    ->dehydrated(false),
                            ]),
                    ])
                    ->action(static function (PlanRecord $record, array $data, CommandBus $commandBus): void {
                        /** @var list<string> $ordered */
                        $ordered = array_values(array_map(
                            static fn (array $stop): string => (string) $stop['shipment_id'],
                            $data['stops'] ?? [],
                        ));

                        try {
                            $commandBus->dispatch(new ReorderStops(
                                planId: (string) $record->getKey(),
                                orderedShipmentIds: $ordered,
                            ));
                        } catch (PlanningException $exception) {
                            self::failure(__('Could not reorder stops'), $exception);

                            return;
                        }

                        self::success(__('Stops reordered'), __('The delivery sequence was updated.'));
                    }),
                // Flujo: publicar el plan (comando PublishPlan). Lo confirma y lo deja disponible para
                // la ejecución. Solo visible cuando está propuesto.
                Action::make('publishPlan')
                    ->label(__('Publish'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Publish this delivery plan'))
                    ->modalDescription(__('Confirm the plan and make it available for execution. This cannot be undone here.'))
                    ->modalSubmitActionLabel(__('Publish'))
                    ->visible(static fn (PlanRecord $record): bool => $record->status === PlanStatus::Proposed->value)
                    ->action(static function (PlanRecord $record, CommandBus $commandBus): void {
                        try {
                            $commandBus->dispatch(new PublishPlan(
                                planId: (string) $record->getKey(),
                            ));
                        } catch (PlanningException $exception) {
                            self::failure(__('Could not publish plan'), $exception);

                            return;
                        }

                        self::success(__('Plan published'), __('The plan is now available for execution.'));
                    }),
            ])
            ->defaultSort('plan_date', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Plan'))
                ->schema([
                    TextEntry::make('plan_date')->label(__('Plan date'))->date(),
                    TextEntry::make('vehicle.plate')->label(__('Vehicle'))->placeholder('—'),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(static fn (string $state): string => self::statusLabel($state))
                        ->color(static fn (string $state): string => self::statusColor($state)),
                ])
                ->columns(2),
            Section::make(__('Stops'))
                ->schema([
                    RepeatableEntry::make('stops')
                        ->label('')
                        ->schema([
                            TextEntry::make('sequence')->label('#'),
                            // Foto legible del destino guardada en la parada (no el UUID del envío).
                            TextEntry::make('order_reference')
                                ->label(__('Order'))
                                ->placeholder(static fn ($record): string => $record?->shipment->external_id ?? '—'),
                            TextEntry::make('customer_name')
                                ->label(__('Customer'))
                                ->placeholder(static fn ($record): string => $record?->shipment->customer_name ?? '—'),
                            TextEntry::make('address')
                                ->label(__('Address'))
                                ->placeholder(static fn ($record): string => $record?->shipment->address_text ?? '—'),
                            TextEntry::make('eta')->label(__('ETA'))->dateTime()->placeholder('—'),
                            TextEntry::make('eta_minutes')->label(__('ETA (min from departure)'))->placeholder('—'),
                            TextEntry::make('lane')->label(__('Load lane'))->placeholder('—'),
                        ])
                        ->columns(7),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'view' => Pages\ViewPlan::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        // El plan no se crea como fila: nace por el flujo "Generate proposal" (comando), en el header.
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        // El estado del plan solo cambia por comandos de aplicación; el admin no lo edita como fila.
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    /**
     * Flujo de cabecera: generar la propuesta automática pedido→camión (comando GeneratePlanProposal).
     *
     * El operador elige día, camión (select buscable con placas reales de Fleet) y los pedidos a incluir
     * (selects buscables con referencias reales de Shipments). Las coordenadas de cada pedido las aporta
     * el propio envío; el handler corre el motor de rutas y deja el plan propuesto con secuencia y ETA.
     */
    public static function generateProposalAction(): Action
    {
        return Action::make('generateProposal')
            ->label(__('Generate proposal'))
            ->icon('heroicon-o-sparkles')
            ->modalHeading(__('Generate an automatic plan proposal'))
            ->modalDescription(__('Pick the truck, the departure time and the shipments. The route engine resolves order and ETA.'))
            ->modalSubmitActionLabel(__('Generate'))
            ->schema([
                Select::make('vehicleId')
                    ->label(__('Vehicle'))
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->getSearchResultsUsing(static fn (string $search): array => VehicleModel::query()
                        ->where('plate', 'like', "%{$search}%")
                        ->orderBy('plate')
                        ->limit(50)
                        ->pluck('plate', 'id')
                        ->all())
                    ->getOptionLabelUsing(static fn (string $value): ?string => VehicleModel::query()
                        ->whereKey($value)
                        ->value('plate')),
                DateTimePicker::make('departAt')
                    ->label(__('Departure time'))
                    ->helperText(__('When the truck leaves the depot. ETA is computed from here.'))
                    ->native(false)
                    ->seconds(false)
                    ->required(),
                Repeater::make('stops')
                    ->label(__('Shipments to include'))
                    ->minItems(1)
                    ->addActionLabel(__('Add shipment'))
                    ->schema([
                        self::shipmentSelect('shipmentId'),
                    ]),
            ])
            ->action(static function (array $data, CommandBus $commandBus): void {
                /** @var list<ProposalStopInput> $stops */
                $stops = [];
                foreach ($data['stops'] ?? [] as $row) {
                    $shipmentId = (string) $row['shipmentId'];
                    $shipment = ShipmentRecord::query()->find($shipmentId);
                    if (!$shipment instanceof ShipmentRecord) {
                        continue;
                    }

                    // Las coordenadas y la foto legible del destino (pedido, cliente, dirección) las
                    // aporta el envío; planificación no las resuelve por su cuenta.
                    $stops[] = new ProposalStopInput(
                        shipmentId: $shipmentId,
                        latitude: (float) $shipment->lat,
                        longitude: (float) $shipment->lng,
                        orderReference: $shipment->external_id,
                        customerName: $shipment->customer_name,
                        address: $shipment->address_text,
                    );
                }

                // El día del plan se deriva de la hora de salida elegida (misma jornada del camión).
                $planDate = substr((string) $data['departAt'], 0, 10);

                try {
                    $commandBus->dispatch(new GeneratePlanProposal(
                        planDate: $planDate,
                        vehicleId: (string) $data['vehicleId'],
                        departAt: (string) $data['departAt'],
                        stops: $stops,
                    ));
                } catch (RouteSpansMultipleDepartments) {
                    self::failureText(
                        __('Could not generate proposal'),
                        __('A delivery route to customers cannot cross departments. All customer stops on a truck must be in the same department (same municipality, or inter-municipal within the same department).'),
                    );

                    return;
                } catch (PlanningException $exception) {
                    self::failure(__('Could not generate proposal'), $exception);

                    return;
                }

                self::success(__('Proposal generated'), __('The plan was proposed with its sequence and ETA.'));
            });
    }

    /**
     * Flujo de cabecera: AUTO-PLANIFICAR el día entero (comando GenerateDailyPlan).
     *
     * El operador elige el día y el modo; el comando encola el cálculo pesado (asíncrono) que arma los
     * camiones, las rutas por cercanía y el plan de carga a partir de los pedidos pendientes. No bloquea
     * la UI: tras disparar, relee la corrida y muestra su estado y, si ya terminó, el resumen (camiones,
     * paradas, pedidos sin asignar).
     */
    public static function autoPlanAction(): Action
    {
        return Action::make('autoPlanTheDay')
            ->label(__('Auto-plan the day'))
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modalHeading(__('Auto-plan the day'))
            ->modalDescription(__('Build trucks, routes and the load plan from pending orders. The heavy work runs in the background.'))
            ->modalSubmitActionLabel(__('Plan'))
            ->schema([
                DatePicker::make('planDate')
                    ->label(__('Plan date'))
                    ->native(false)
                    ->required(),
                Select::make('strategy')
                    ->label(__('Mode'))
                    ->native(false)
                    ->required()
                    ->default(PlanStrategy::Optimization->value)
                    ->options([
                        PlanStrategy::Optimization->value => __('Optimization (capacity + proximity)'),
                        PlanStrategy::Slots->value => __('Delivery slots (respect the customer time slot)'),
                    ])
                    ->helperText(__('Optimization packs by capacity and proximity; Slots groups by the chosen delivery time slot.')),
            ])
            ->action(static function (array $data, CommandBus $commandBus, QueryBus $queryBus): void {
                $planDate = (string) $data['planDate'];
                $strategy = (string) $data['strategy'];

                try {
                    $commandBus->dispatch(new GenerateDailyPlan(
                        planDate: $planDate,
                        strategy: $strategy,
                    ));
                } catch (PlanningException $exception) {
                    self::failure(__('Could not start the auto-plan'), $exception);

                    return;
                }

                /** @var AutoPlanRunView|null $run */
                $run = $queryBus->ask(new GetDailyPlanRun($planDate, $strategy));

                if ($run instanceof AutoPlanRunView && $run->status === 'completed') {
                    self::success(
                        __('Day planned'),
                        __(':trucks trucks, :stops stops, :unassigned orders left unassigned.', [
                            'trucks' => $run->trucksPlanned,
                            'stops' => $run->stopsPlanned,
                            'unassigned' => $run->unassignedCount,
                        ]),
                    );

                    return;
                }

                self::success(
                    __('Auto-plan queued'),
                    __('The day is being planned in the background. Refresh in a moment to see the trucks.'),
                );
            });
    }

    /**
     * Select buscable de envíos con datos reales de Shipments: muestra referencia y cliente, no el UUID.
     */
    private static function shipmentSelect(string $name): Select
    {
        return Select::make($name)
            ->label(__('Shipment'))
            ->required()
            ->native(false)
            ->searchable()
            ->getSearchResultsUsing(static fn (string $search): array => ShipmentRecord::query()
                ->where(static function (Builder $query) use ($search): void {
                    $query->where('external_id', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%");
                })
                ->orderBy('external_id')
                ->limit(50)
                ->get()
                ->mapWithKeys(static fn (ShipmentRecord $record): array => [
                    $record->id => self::shipmentLabel($record) ?? $record->id,
                ])
                ->all())
            ->getOptionLabelUsing(static function (string $value): ?string {
                $record = ShipmentRecord::query()->find($value);

                return $record instanceof ShipmentRecord ? self::shipmentLabel($record) : null;
            });
    }

    /**
     * Etiqueta humana de un envío: "referencia — cliente".
     */
    private static function shipmentLabel(?ShipmentRecord $record): ?string
    {
        if (!$record instanceof ShipmentRecord) {
            return null;
        }

        return "{$record->external_id} — {$record->customer_name}";
    }

    /**
     * Opciones de estado (valor del enum → etiqueta en inglés) para el filtro.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];
        foreach (PlanStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status->value);
        }

        return $options;
    }

    /**
     * Etiqueta legible en inglés para un estado almacenado.
     */
    private static function statusLabel(string $state): string
    {
        return match ($state) {
            PlanStatus::Draft->value => __('Draft'),
            PlanStatus::Proposed->value => __('Proposed'),
            PlanStatus::Published->value => __('Published'),
            default => $state,
        };
    }

    /**
     * Color del badge por estado.
     */
    private static function statusColor(string $state): string
    {
        return match ($state) {
            PlanStatus::Draft->value => 'gray',
            PlanStatus::Proposed->value => 'warning',
            PlanStatus::Published->value => 'success',
            default => 'gray',
        };
    }

    /**
     * Notificación de éxito de un flujo.
     */
    private static function success(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }

    /**
     * Notificación de error de dominio de un flujo. Acepta tanto PlanningException como la excepción
     * de la regla geográfica (RouteSpansMultipleDepartments); el cuerpo es el mensaje legible del error.
     */
    private static function failure(string $title, \Throwable $exception): void
    {
        self::failureText($title, $exception->getMessage());
    }

    /**
     * Notificación de error con un cuerpo de texto ya resuelto (para mensajes en inglés vía __()).
     */
    private static function failureText(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }

    /**
     * Convierte una coordenada almacenada (string|null) en float, o null si no es numérica. Permite
     * alimentar la validación geográfica sin romper cuando el envío no tiene coordenadas.
     */
    private static function coordinate(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Paradas de CLIENTE que ya tiene el plan, con sus coordenadas tomadas del envío. Solo incluye las
     * paradas que entregan a un cliente y tienen coordenadas; los traspasos CD→CD y las paradas sin
     * cliente o sin coordenadas quedan fuera (exentos de la regla geográfica).
     *
     * @return list<ClientStopLocation>
     */
    private static function clientStopsOf(PlanRecord $record): array
    {
        $stops = [];
        foreach ($record->stops()->with('shipment')->get() as $stop) {
            $shipment = $stop->shipment;
            if (!$shipment instanceof ShipmentRecord) {
                continue;
            }
            $customerName = $shipment->customer_name;
            if ($customerName === null || trim($customerName) === '') {
                continue;
            }
            $lat = self::coordinate($shipment->lat);
            $lng = self::coordinate($shipment->lng);
            if ($lat === null || $lng === null) {
                continue;
            }
            $stops[] = ClientStopLocation::of((string) $stop->shipment_id, $lat, $lng);
        }

        return $stops;
    }
}
