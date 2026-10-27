<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Pages;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;
use Logistics\Shared\Presentation\Filament\ImageFallback;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;

/**
 * Cockpit de última milla / despacho para el supervisor.
 *
 * NO es un CRUD: es la sala de monitoreo-y-acción de las entregas del día. Muestra las entregas
 * activas (y las cerradas hoy) segmentadas por estado operativo —en preparación, en ruta, entregadas,
 * fallidas— con datos legibles (referencia del pedido, cliente, dirección, placa del vehículo, ventana
 * prometida vs. entrega real) y NUNCA UUIDs. Cada fila activa lleva sus acciones de dominio inline, que
 * despachan los Commands ya existentes de execution por el CommandBus (Presentation → Application, nunca
 * Infrastructure ni Domain directo), de modo que se respetan las invariantes del agregado y el outbox
 * transaccional. Los choferes operan desde la app Flutter; aquí el supervisor observa y actúa.
 *
 * Lectura: la cola se arma desde la proyección de lectura (execution_deliveries, con pedido y vehículo
 * en eager-load) solo para presentación, tolerando tablas ausentes (patrón ReadsControlTowerData). Las
 * MUTACIONES siempre pasan por comandos. Cada texto visible en inglés (regla 5); comentarios en español.
 */
final class LastMileBoard extends Page
{
    use ResolvesUserRoles;

    /**
     * Tablero de última milla: la oficina despacha y el chofer trabaja su cola de entregas del día.
     *
     * Sobrescribir canAccess() basta: Filament lo usa para ocultar del menú y bloquear la URL (403).
     */
    public static function canAccess(): bool
    {
        return self::authUserHasAnyRoleValue('admin', 'supervisor', 'chofer');
    }

    protected static string | BackedEnum | null $navigationIcon = 'ds-truck';

    protected static ?int $navigationSort = 5;

    protected string $view = 'logistics-execution::filament.pages.last-mile-board';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Last-mile board');
    }

    public function getTitle(): string
    {
        return __('Last-mile board');
    }

    /**
     * Datos de la vista: segmentos por estado con sus filas legibles y el placeholder de las fotos.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'segments' => $this->segments(),
            'podPlaceholder' => ImageFallback::placeholder('box'),
            'generatedAt' => now(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Lectura (solo presentación): cola de entregas del día agrupada por estado operativo.
    // ---------------------------------------------------------------------------------------------

    /**
     * Segmentos del tablero, en orden operativo (lo que requiere acción primero). Cada segmento agrupa
     * uno o varios estados de entrega y trae sus filas ya enriquecidas para pintar.
     *
     * @return list<array{key: string, label: string, color: string, actionable: bool, count: int, rows: list<array<string, mixed>>}>
     */
    private function segments(): array
    {
        $rows = $this->rows();

        /** @var array<string, array{statuses: list<string>, label: string, color: string, actionable: bool}> $definitions */
        $definitions = [
            'preparing' => [
                'statuses' => [DeliveryStatus::Pending->value, DeliveryStatus::Prepared->value, DeliveryStatus::Loaded->value],
                'label' => __('Preparing'),
                'color' => 'warning',
                'actionable' => true,
            ],
            'in_route' => [
                'statuses' => [DeliveryStatus::InTransit->value],
                'label' => __('In route'),
                'color' => 'info',
                'actionable' => true,
            ],
            'delivered' => [
                'statuses' => [DeliveryStatus::Delivered->value],
                'label' => __('Delivered'),
                'color' => 'success',
                'actionable' => false,
            ],
            'failed' => [
                'statuses' => [DeliveryStatus::Failed->value],
                'label' => __('Failed'),
                'color' => 'danger',
                'actionable' => false,
            ],
        ];

        $segments = [];
        foreach ($definitions as $key => $definition) {
            $matching = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['status'], $definition['statuses'], true),
            ));

            $segments[] = [
                'key' => $key,
                'label' => $definition['label'],
                'color' => $definition['color'],
                'actionable' => $definition['actionable'],
                'count' => count($matching),
                'rows' => $matching,
            ];
        }

        return $segments;
    }

    /**
     * Filas del día: entregas activas (estado no cerrado) más las cerradas hoy, enriquecidas con datos
     * legibles. Tolera que la tabla no exista todavía (entorno sin migrar): devuelve una cola vacía.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        if (!Schema::hasTable('execution_deliveries')) {
            return [];
        }

        $startOfToday = now()->startOfDay();
        $closed = [DeliveryStatus::Delivered->value, DeliveryStatus::Failed->value];

        try {
            $records = DeliveryRecord::query()
                ->with(['shipment', 'vehicle'])
                // Activas (no cerradas) o cerradas/abiertas hoy: la cola viva del supervisor.
                ->where(function (Builder $query) use ($closed, $startOfToday): void {
                    // `created_at` es columna de timestamps (no está en el docblock del modelo): se
                    // referencia por SQL crudo para no chocar con el chequeo de propiedades de larastan.
                    $query->whereNotIn('status', $closed)
                        ->orWhereRaw('created_at >= ?', [$startOfToday])
                        ->orWhere('pod_captured_at', '>=', $startOfToday);
                })
                ->orderByRaw('created_at asc')
                ->limit(500)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return array_values($records
            ->map(fn (DeliveryRecord $record): array => $this->row($record))
            ->all());
    }

    /**
     * Mapea una entrega a una fila legible: referencia del pedido, cliente, dirección, placa, ventana
     * prometida y entrega real. Nunca expone UUIDs; los huecos caen a null (el blade pinta '—').
     *
     * @return array<string, mixed>
     */
    private function row(DeliveryRecord $record): array
    {
        $shipment = $record->shipment;
        $vehicle = $record->vehicle;

        $promised = null;
        if ($shipment !== null && $shipment->delivery_date !== null && $shipment->delivery_date !== '') {
            $window = trim((string) $shipment->delivery_window_start.'-'.(string) $shipment->delivery_window_end, '-');
            $promised = trim((string) $shipment->delivery_date.' '.$window);
        }

        return [
            'id' => $record->id,
            'shipment_id' => $record->shipment_id,
            'order' => $shipment->external_id ?? null,
            'customer' => $shipment->customer_name ?? null,
            'address' => $shipment->address_text ?? null,
            'vehicle' => $vehicle->plate ?? null,
            'promised' => $promised !== '' ? $promised : null,
            'status' => $record->status,
            'status_label' => $this->statusLabel($record->status),
            'status_color' => $this->statusColor($record->status),
            'delivered_at' => $record->pod_captured_at,
            'failure_reason' => $record->failure_reason === null ? null : $this->failureReasonLabel($record->failure_reason),
            'pod_photo_url' => $record->pod_photo_url,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Acciones inline (CQRS): cada una despacha un Command ya existente por el CommandBus. Se invocan
    // por fila desde el blade pasando el pedido como argumento, p. ej.:
    //     {{ ($this->confirmDeliveryAction)(['shipment' => $row['shipment_id']]) }}
    // ---------------------------------------------------------------------------------------------

    /**
     * "Confirm delivery (POD)": cierra la entrega con la confirmación del cliente (QR o firma) y una
     * foto opcional. Despacha RecordProofOfDelivery (mismo Command que usa DeliveryResource).
     */
    public function confirmDeliveryAction(): Action
    {
        return Action::make('confirmDelivery')
            ->label(__('Confirm delivery (POD)'))
            ->icon('ds-check-badge')
            ->extraAttributes(['class' => 'ds-icon-pop-hover'])
            ->color('success')
            ->modalHeading(__('Record proof of delivery'))
            ->modalDescription(__('Confirm the delivery with the customer confirmation (QR or signature) and an optional photo.'))
            ->modalSubmitActionLabel(__('Confirm delivery'))
            ->schema(fn (array $arguments): array => [
                $this->unitSelect($this->argShipment($arguments)),
                TextInput::make('confirmation')
                    ->label(__('Customer confirmation'))
                    ->helperText(__('Customer QR code or signature reference.'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('photo_url')
                    ->label(__('Photo URL'))
                    ->url()
                    ->maxLength(2048),
                ...$this->contextFields(),
            ])
            ->action(function (array $data, array $arguments): void {
                $photo = isset($data['photo_url']) && $data['photo_url'] !== '' ? (string) $data['photo_url'] : null;

                $this->dispatchCommand(new RecordProofOfDelivery(
                    shipmentId: $this->argShipment($arguments),
                    unitId: (string) $data['unit_id'],
                    confirmation: (string) $data['confirmation'],
                    photoUrl: $photo,
                    photoPath: null,
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: $this->iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Proof of delivery recorded'));
            });
    }

    /**
     * "Mark failed": cierra la entrega como fallida con un motivo tipificado. Despacha
     * RecordFailedDelivery (mismo Command que usa DeliveryResource).
     */
    public function markFailedAction(): Action
    {
        return Action::make('markFailed')
            ->label(__('Mark failed'))
            ->icon('ds-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Record failed delivery'))
            ->modalDescription(__('Close the delivery as failed with a typified reason.'))
            ->modalSubmitActionLabel(__('Record failure'))
            ->schema(fn (array $arguments): array => [
                $this->unitSelect($this->argShipment($arguments)),
                Select::make('reason')
                    ->label(__('Failure reason'))
                    ->required()
                    ->native(false)
                    ->options($this->failureReasonOptions()),
                ...$this->contextFields(),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->dispatchCommand(new RecordFailedDelivery(
                    shipmentId: $this->argShipment($arguments),
                    unitId: (string) $data['unit_id'],
                    reason: (string) $data['reason'],
                    lat: (float) $data['lat'],
                    lng: (float) $data['lng'],
                    deviceId: (string) $data['device_id'],
                    userId: (string) $data['user_id'],
                    occurredAt: $this->iso($data['occurred_at']),
                    idempotencyKey: (string) Str::uuid(),
                ), __('Failed delivery recorded'));
            });
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers de despacho y formulario (replican el patrón de DeliveryResource en esta capa).
    // ---------------------------------------------------------------------------------------------

    /**
     * Despacha un comando por el CommandBus y notifica el resultado. No se llama `dispatch` a propósito:
     * ese nombre ya lo usa Livewire para emitir eventos de navegador.
     */
    private function dispatchCommand(Command $command, string $successTitle): void
    {
        try {
            /** @var CommandBus $bus */
            $bus = app(CommandBus::class);
            $bus->dispatch($command);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Could not record the action'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Pedido (shipment_id) que llega como argumento de la acción inline, como string.
     *
     * @param array<string, mixed> $arguments
     */
    private function argShipment(array $arguments): string
    {
        return isset($arguments['shipment']) && is_string($arguments['shipment']) ? $arguments['shipment'] : '';
    }

    /**
     * Select de producto acotado a los productos del pedido de la entrega (misma lógica que DeliveryResource).
     * Las etiquetas son legibles (pedido + índice de producto/pieza), nunca el UUID.
     */
    private function unitSelect(string $shipmentId): Select
    {
        $units = [];
        if ($shipmentId !== '') {
            $units = PhysicalUnitRecord::query()
                ->where('shipment_id', $shipmentId)
                ->with(['shipment', 'line'])
                ->get()
                ->mapWithKeys(fn (PhysicalUnitRecord $unit): array => [$unit->id => $this->unitLabel($unit)])
                ->all();
        }

        return Select::make('unit_id')
            ->label(__('Unit'))
            ->required()
            ->native(false)
            ->searchable()
            ->options($units);
    }

    /**
     * Etiqueta humana del producto: pedido + nombre del producto y su SKU (+ pieza física N de M),
     * nunca el UUID ni vacío. Si falta el nombre se usa el SKU y, si faltan ambos, "Producto".
     */
    private function unitLabel(PhysicalUnitRecord $unit): string
    {
        $order = $unit->shipment->external_id ?? $unit->shipment_id;

        $name = is_string($unit->line?->product_name) ? trim($unit->line->product_name) : '';
        $sku = is_string($unit->line?->sku) ? trim($unit->line->sku) : '';
        if ($name !== '' && $sku !== '') {
            $product = "{$name} · {$sku}";
        } elseif ($name !== '') {
            $product = $name;
        } elseif ($sku !== '') {
            $product = $sku;
        } else {
            $product = 'Producto';
        }

        $piece = '';
        if ($unit->piece_total > 1) {
            $piece = "({$unit->piece_index} de {$unit->piece_total})";
        } elseif ($unit->unit_total > 1) {
            $piece = "({$unit->unit_index} de {$unit->unit_total})";
        }

        return trim("{$order} — {$product} {$piece}");
    }

    /**
     * Campos comunes de cada escaneo: GPS, dispositivo, usuario y hora del hecho.
     *
     * @return array<int, Component>
     */
    private function contextFields(): array
    {
        return [
            TextInput::make('lat')
                ->label(__('Latitude'))
                ->numeric()
                ->required()
                ->minValue(-90)
                ->maxValue(90),
            TextInput::make('lng')
                ->label(__('Longitude'))
                ->numeric()
                ->required()
                ->minValue(-180)
                ->maxValue(180),
            TextInput::make('device_id')
                ->label(__('Device'))
                ->required()
                ->default('admin-panel')
                ->maxLength(255),
            TextInput::make('user_id')
                ->label(__('User'))
                ->required()
                ->default(static fn (): string => (string) (auth()->user()?->getAuthIdentifier() ?? 'admin'))
                ->maxLength(255),
            DateTimePicker::make('occurred_at')
                ->label(__('Occurred at'))
                ->seconds(false)
                ->required()
                ->default(static fn (): CarbonInterface => now()),
        ];
    }

    /**
     * Normaliza la hora del formulario a ISO-8601 para el Command.
     */
    private function iso(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * Etiqueta en inglés del estado de entrega.
     */
    private function statusLabel(string $value): string
    {
        return $this->statusOptions()[$value] ?? $value;
    }

    /**
     * Color semántico del badge de estado.
     */
    private function statusColor(string $value): string
    {
        return match ($value) {
            DeliveryStatus::Delivered->value => 'success',
            DeliveryStatus::Failed->value => 'danger',
            DeliveryStatus::InTransit->value => 'info',
            DeliveryStatus::Loaded->value, DeliveryStatus::Prepared->value => 'warning',
            default => 'gray',
        };
    }

    /**
     * Etiqueta en inglés del motivo de fallo.
     */
    private function failureReasonLabel(string $value): string
    {
        return $this->failureReasonOptions()[$value] ?? $value;
    }

    /**
     * Opciones del estado de entrega como value → label en inglés.
     *
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        return [
            DeliveryStatus::Pending->value => __('Pending'),
            DeliveryStatus::Prepared->value => __('Prepared'),
            DeliveryStatus::Loaded->value => __('Loaded'),
            DeliveryStatus::InTransit->value => __('In transit'),
            DeliveryStatus::Delivered->value => __('Delivered'),
            DeliveryStatus::Failed->value => __('Failed'),
        ];
    }

    /**
     * Opciones del motivo de fallo como value → label en inglés.
     *
     * @return array<string, string>
     */
    private function failureReasonOptions(): array
    {
        return [
            FailureReason::CustomerAbsent->value => __('Customer absent'),
            FailureReason::WrongAddress->value => __('Wrong address'),
            FailureReason::Rejected->value => __('Rejected'),
            FailureReason::Damaged->value => __('Damaged'),
            FailureReason::Other->value => __('Other'),
        ];
    }
}
