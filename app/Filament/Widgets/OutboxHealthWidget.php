<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Torre de control — salud del outbox: el latido de todo el I/O externo de la plataforma (SAP, FCM,
 * email, SMS, transportistas) pasa por aquí (ADR 002). Pendiente y fallido en aumento, o cualquier
 * dead-letter, son señales de que el relay no está drenando la cola.
 *
 * Lee `outbox_messages` por conteo directo (solo lectura) y tolera la tabla ausente.
 */
final class OutboxHealthWidget extends StatsOverviewWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Outbox health';

    protected ?string $description = 'Outbound messages to external systems (SAP, FCM, email, SMS, carriers).';

    // Refresco en vivo: la torre de control debe reflejar el drenaje del relay sin recargar.
    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = 4;

    protected function getStats(): array
    {
        $byStatus = $this->countByStatus('outbox_messages');

        $pending = $byStatus['pending'] ?? 0;
        $failed = $byStatus['failed'] ?? 0;
        $deadLettered = $byStatus['dead_lettered'] ?? 0;
        $dispatched = $byStatus['dispatched'] ?? 0;

        return [
            Stat::make(__('Pending'), (string) $pending)
                ->description(__('Waiting to be dispatched'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make(__('Failed'), (string) $failed)
                ->description(__('Will be retried'))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($failed > 0 ? 'warning' : 'gray'),

            Stat::make(__('Dead-letter'), (string) $deadLettered)
                ->description(__('Exhausted retries, need attention'))
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($deadLettered > 0 ? 'danger' : 'success'),

            Stat::make(__('Dispatched'), (string) $dispatched)
                ->description(__('Delivered to external systems'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
        ];
    }
}
