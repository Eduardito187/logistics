<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ReadsControlTowerData;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Torre de control — salud de las colas de trabajos. El relay del outbox y los procesos asíncronos
 * corren sobre la cola `database` (ver config/queue.php). Trabajos encolados que no bajan, o trabajos
 * fallidos que suben, indican que los workers no están procesando.
 *
 * Lee `jobs` y `failed_jobs` por conteo directo (solo lectura) y tolera las tablas ausentes: si la
 * cola corre sobre otro driver (Redis/SQS) estas tablas pueden no existir y el widget muestra cero.
 */
final class QueueHealthWidget extends StatsOverviewWidget
{
    use ReadsControlTowerData;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Queue health';

    protected ?string $description = 'Background workers draining the outbox relay and async jobs.';

    protected ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = 3;

    protected function getStats(): array
    {
        $queued = $this->count('jobs');
        $failed = $this->count('failed_jobs');

        return [
            Stat::make(__('Queued jobs'), (string) $queued)
                ->description(__('Waiting for a worker'))
                ->descriptionIcon('heroicon-m-queue-list')
                ->color($queued > 0 ? 'warning' : 'gray'),

            Stat::make(__('Oldest job wait'), $this->oldestJobWait())
                ->description(__('Backlog age of the queue'))
                ->descriptionIcon('heroicon-m-clock')
                ->color('gray'),

            Stat::make(__('Failed jobs'), (string) $failed)
                ->description(__('Need to be retried or purged'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Antigüedad legible del trabajo más viejo aún en cola (según `available_at`, epoch en segundos).
     * Un "—" significa que no hay backlog (o que la tabla no existe). El formato se arma a mano para
     * no depender de los métodos dinámicos de Carbon (y mantener limpio el análisis estático).
     */
    private function oldestJobWait(): string
    {
        try {
            $availableAt = DB::table('jobs')->min('available_at');

            if ($availableAt === null) {
                return '—';
            }

            $seconds = max(0, now()->getTimestamp() - (int) $availableAt);

            if ($seconds < 60) {
                return $seconds.'s';
            }

            if ($seconds < 3600) {
                return intdiv($seconds, 60).'m';
            }

            if ($seconds < 86400) {
                return intdiv($seconds, 3600).'h';
            }

            return intdiv($seconds, 86400).'d';
        } catch (\Throwable) {
            return '—';
        }
    }
}
