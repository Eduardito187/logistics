<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;

/**
 * Torre de control — notificaciones de hoy por estado (cascada de canales del ADR 006). Un pico de
 * fallidas o suprimidas avisa que la cascada no llega al cliente (p. ej. tope de SMS, push caído).
 * La ventana "hoy" usa la zona de la plataforma (America/La_Paz).
 *
 * Lee `notifications` por conteo directo (solo lectura), acotado a las filas tocadas hoy
 * (`updated_at`), y tolera la tabla ausente. Los estados son los de {@see NotificationStatus}.
 */
final class NotificationsTodayWidget extends StatsOverviewWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Notifications today';

    protected ?string $description = 'Customer notifications across the channel cascade.';

    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = 4;

    protected function getStats(): array
    {
        $byStatus = $this->countByStatus('notifications', 'updated_at');

        $queued = $byStatus['QUEUED'] ?? 0;
        $sent = $byStatus['SENT'] ?? 0;
        $failed = $byStatus['FAILED'] ?? 0;
        $suppressed = $byStatus['SUPPRESSED'] ?? 0;

        return [
            Stat::make(__('Queued'), (string) $queued)
                ->description(__('Awaiting a channel in the cascade'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($queued > 0 ? 'warning' : 'gray'),

            Stat::make(__('Sent'), (string) $sent)
                ->description(__('Reached the customer'))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('success'),

            Stat::make(__('Failed'), (string) $failed)
                ->description(__('Exhausted the cascade'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),

            Stat::make(__('Suppressed'), (string) $suppressed)
                ->description(__('Not sent by rule or budget'))
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color('gray'),
        ];
    }
}
