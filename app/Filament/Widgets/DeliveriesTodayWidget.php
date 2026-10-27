<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;

/**
 * Torre de control — entregas de hoy por estado. El pulso del día operativo: cuánto está en ruta,
 * cuánto se entregó y cuánto falló (necesita reprogramación). La ventana "hoy" usa la zona de la
 * plataforma (America/La_Paz).
 *
 * Lee `execution_deliveries` por conteo directo (solo lectura), acotado a las filas tocadas hoy
 * (`updated_at`), y tolera la tabla ausente. Los estados son los de {@see DeliveryStatus}.
 */
final class DeliveriesTodayWidget extends StatsOverviewWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Deliveries today';

    protected ?string $description = 'Execution status of deliveries touched today.';

    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = 4;

    protected function getStats(): array
    {
        $byStatus = $this->countByStatus('execution_deliveries', 'updated_at');

        // Pendientes de salir: todo lo que aún no está en ruta ni cerrado.
        $inPipeline = ($byStatus['PENDING'] ?? 0) + ($byStatus['PREPARED'] ?? 0) + ($byStatus['LOADED'] ?? 0);
        $inTransit = $byStatus['IN_TRANSIT'] ?? 0;
        $delivered = $byStatus['DELIVERED'] ?? 0;
        $failed = $byStatus['FAILED'] ?? 0;

        return [
            Stat::make(__('In preparation'), (string) $inPipeline)
                ->description(__('Pending, prepared or loaded'))
                ->descriptionIcon('ds-inbox-stack')
                ->color('gray'),

            Stat::make(__('Out for delivery'), (string) $inTransit)
                ->description(__('On route right now'))
                ->descriptionIcon('ds-truck')
                ->color($inTransit > 0 ? 'warning' : 'gray'),

            Stat::make(__('Delivered'), (string) $delivered)
                ->description(__('Completed today'))
                ->descriptionIcon('ds-check-circle')
                ->color('success'),

            Stat::make(__('Failed deliveries'), (string) $failed)
                ->description(__('Need rescheduling'))
                ->descriptionIcon('ds-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
