<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Ola 4 — Diagnóstico de profundidad de colas y señales de autoscaling.
 *
 * Reporta la profundidad de cada cola y, si alguna excede el umbral, sale con código 1 para que un
 * orquestador (K8s HPA, Docker Swarm, cron+script) lo use como señal para escalar workers.
 *
 * Uso manual (inspección):
 *   php artisan logistics:queue:health
 *
 * Uso en un script de autoscaling:
 *   if php artisan logistics:queue:health --threshold=500 --exit-on-overload; then
 *       echo "Queues healthy"
 *   else
 *       echo "Scaling up workers..."
 *       # docker compose up -d --scale queue-worker=4
 *   fi
 *
 * También se programa en el scheduler para emitir logs de profundidad y detectar acumulación.
 */
final class QueueHealthCheckCommand extends Command
{
    protected $signature = 'logistics:queue:health
        {--threshold=1000 : Job count above which a queue is considered overloaded}
        {--exit-on-overload : Exit with code 1 if any queue exceeds the threshold (for scripting)}
        {--queues= : Comma-separated list of queues to check (default: all known)}';

    protected $description = 'Report queue depths and signal overload for autoscaling decisions';

    /**
     * Colas conocidas del proyecto (alineadas con config/queue.php y supervisord.conf).
     *
     * @var list<string>
     */
    private const KNOWN_QUEUES = ['default', 'outbox', 'notifications', 'sap', 'planning'];

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $exitOnOverload = (bool) $this->option('exit-on-overload');
        $queues = $this->resolveQueues();

        $prefix = config('database.redis.options.prefix', '');
        $overloaded = false;

        $this->table(['Queue', 'Depth', 'Status'], collect($queues)->map(function (string $queue) use ($prefix, $threshold, &$overloaded): array {
            $key = $prefix.'queues:'.$queue;
            $depth = (int) Redis::connection('default')->llen($key);

            $status = 'OK';
            if ($depth >= $threshold) {
                $status = 'OVERLOADED';
                $overloaded = true;
            } elseif ($depth > $threshold * 0.7) {
                $status = 'WARNING';
            }

            return [$queue, $depth, $status];
        })->all());

        if ($overloaded) {
            $this->warn(sprintf('Una o más colas exceden el umbral de %d jobs. Considerar escalar workers.', $threshold));

            return $exitOnOverload ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Todas las colas dentro del umbral.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveQueues(): array
    {
        $option = $this->option('queues');
        if (!is_string($option) || trim($option) === '') {
            return self::KNOWN_QUEUES;
        }

        return array_values(array_filter(array_map('trim', explode(',', $option)), static fn (string $q): bool => $q !== ''));
    }
}
