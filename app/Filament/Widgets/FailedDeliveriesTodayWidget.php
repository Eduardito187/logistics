<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Throwable;

/**
 * Torre de control — EXCEPCIÓN: entregas que fallaron hoy. Es lo primero que debe mirar operaciones:
 * cada fila es un pedido que no llegó al cliente y necesita reprogramación. Enlaza (drill-down) a la
 * ficha de la entrega en {@see DeliveryResource}, donde está la acción de cierre/reproceso.
 *
 * Lee `execution_deliveries` (status FAILED, tocadas hoy) por consulta directa (solo lectura) y
 * resuelve el nombre del cliente desde `shipments` sin exponer UUIDs. Tolera tablas ausentes: si el
 * contexto no corrió migraciones devuelve una tabla vacía en vez de romper el tablero.
 */
final class FailedDeliveriesTodayWidget extends TableWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 1;

    // Refresco en vivo: las excepciones del día deben aparecer sin recargar el panel.
    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Failed deliveries today'))
            ->description(__('Deliveries that failed today and need rescheduling.'))
            ->records(fn (): array => $this->rows())
            // Carga diferida: la torre pinta primero y la tabla llega después, sin bloquear el render.
            ->deferLoading()
            ->paginated([10, 25])
            ->emptyStateHeading(__('No failed deliveries today'))
            ->emptyStateDescription(__('Every delivery touched today is on track.'))
            ->emptyStateIcon('ds-check-badge')
            ->recordUrl(fn (array $record): ?string => $record['url'])
            ->columns([
                TextColumn::make('customer')
                    ->label(__('Customer'))
                    ->weight('medium'),
                TextColumn::make('reason')
                    ->label(__('Failure reason'))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('failed_at')
                    ->label(__('When')),
            ]);
    }

    /**
     * Filas del widget: entregas fallidas hoy, ya resueltas a texto legible y ordenadas de la más
     * reciente a la más antigua. Tolera cualquier fallo de lectura devolviendo una tabla vacía.
     *
     * @return list<array{__key: string, customer: string, reason: string, failed_at: string, url: string|null}>
     */
    private function rows(): array
    {
        try {
            $deliveries = DB::table('execution_deliveries')
                ->where('status', 'FAILED')
                ->where('updated_at', '>=', $this->startOfToday())
                ->orderByDesc('updated_at')
                ->limit(25)
                ->get();
        } catch (Throwable) {
            return [];
        }

        if ($deliveries->isEmpty()) {
            return [];
        }

        $customers = $this->customerNames(
            $deliveries->pluck('shipment_id')->map(static fn ($id): string => (string) $id)->all(),
        );

        $rows = [];
        foreach ($deliveries as $delivery) {
            $deliveryId = (string) $delivery->id;
            $shipmentId = (string) $delivery->shipment_id;
            $reason = (string) ($delivery->failure_reason ?? '');

            $rows[] = [
                '__key' => $deliveryId,
                'customer' => $customers[$shipmentId] ?? '—',
                'reason' => $reason !== '' ? $this->humanize($reason) : '—',
                'failed_at' => Carbon::parse((string) $delivery->updated_at)->format('Y-m-d H:i'),
                'url' => $this->deliveryUrl($deliveryId),
            ];
        }

        return $rows;
    }

    /**
     * Mapa shipment_id => nombre de cliente desde `shipments`, para no mostrar UUIDs. Vacío si la
     * tabla no existe.
     *
     * @param  array<string>         $shipmentIds
     * @return array<string, string>
     */
    private function customerNames(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        try {
            return DB::table('shipments')
                ->whereIn('id', $shipmentIds)
                ->pluck('customer_name', 'id')
                ->map(static fn ($name): string => (string) $name)
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Vuelve legible un motivo tipificado (p. ej. `customer_absent` -> `Customer absent`).
     */
    private function humanize(string $reason): string
    {
        return ucfirst(str_replace('_', ' ', strtolower($reason)));
    }

    /**
     * URL a la ficha de la entrega; null si la ruta no resuelve.
     */
    private function deliveryUrl(string $deliveryId): ?string
    {
        try {
            return DeliveryResource::getUrl('view', ['record' => $deliveryId]);
        } catch (Throwable) {
            return null;
        }
    }
}
