<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use stdClass;

/**
 * Ola 4 — Monitoreo del lag de replicación de PostgreSQL.
 *
 * Consulta al primario el estado de replicación de los standbys conectados y reporta el lag en
 * bytes y/o tiempo. Útil para:
 *   - Verificar que el standby está al día antes de un failover.
 *   - Alertar si el lag excede un umbral (el scraper de métricas ya exporta `logistics_db_replication_lag_bytes`
 *     en MetricsCollector, pero este comando da una vista humana).
 *   - Incluirlo en el pre-deploy check para no cortar el primario si el standby está retrasado.
 *
 * Solo funciona si se ejecuta contra el PRIMARIO (no el standby). En un entorno sin réplica,
 * informa que no hay standbys conectados y sale OK.
 *
 * Solo PostgreSQL.
 */
final class ReplicationLagCommand extends Command
{
    protected $signature = 'logistics:replication:lag
        {--warn-bytes=16777216 : Warn if lag exceeds this many bytes (default 16 MB)}
        {--exit-on-lag : Exit with code 1 if lag exceeds the threshold}';

    protected $description = 'Show PostgreSQL streaming replication lag for connected standbys';

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection();
        $skipReason = $this->skipReason($connection);

        if ($skipReason !== null) {
            $this->warn($skipReason);

            return self::SUCCESS;
        }

        $standbys = $connection->select('
            SELECT
                client_addr,
                state,
                sent_lsn,
                write_lsn,
                flush_lsn,
                replay_lsn,
                pg_wal_lsn_diff(sent_lsn, replay_lsn)::bigint AS lag_bytes,
                (EXTRACT(EPOCH FROM replay_lag) * 1000)::bigint AS lag_ms
            FROM pg_stat_replication
            ORDER BY client_addr
        ');

        return $this->report($standbys);
    }

    /**
     * Motivo por el que no aplica medir el lag acá (otro motor, o este nodo es un standby); null si aplica.
     */
    private function skipReason(ConnectionInterface $connection): ?string
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return 'logistics:replication:lag solo aplica a PostgreSQL.';
        }

        $isRecovery = $connection->selectOne('SELECT pg_is_in_recovery() AS recovering');
        if ($isRecovery !== null && ($isRecovery->recovering ?? false)) {
            return 'Este nodo es un STANDBY, no el primario. El lag se consulta desde el primario.';
        }

        return null;
    }

    /**
     * Imprime la tabla de standbys y devuelve el código de salida según el umbral.
     *
     * @param array<int, stdClass> $standbys
     */
    private function report(array $standbys): int
    {
        if ($standbys === []) {
            $this->info('No hay standbys conectados por streaming replication.');

            return self::SUCCESS;
        }

        $warnBytes = max(0, (int) $this->option('warn-bytes'));
        $anyLagging = false;

        $rows = [];
        foreach ($standbys as $s) {
            $lagBytes = (int) ($s->lag_bytes ?? 0);
            $lagMs = (int) ($s->lag_ms ?? 0);
            $lagging = $lagBytes > $warnBytes;
            $anyLagging = $anyLagging || $lagging;

            $rows[] = [
                $s->client_addr ?? '?',
                $s->state ?? '?',
                $this->humanSize($lagBytes),
                $lagMs > 0 ? sprintf('%d ms', $lagMs) : '< 1 ms',
                $lagging ? 'LAGGING' : 'OK',
            ];
        }

        $this->table(['Client', 'State', 'Lag (bytes)', 'Lag (time)', 'Status'], $rows);

        if (!$anyLagging) {
            $this->info('Todos los standbys dentro del umbral de lag.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Uno o más standbys exceden el umbral de lag (%s).', $this->humanSize($warnBytes)));

        return (bool) $this->option('exit-on-lag') ? self::FAILURE : self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $factor < count($units) - 1) {
            $size /= 1024;
            $factor++;
        }

        return sprintf('%.1f %s', $size, $units[$factor]);
    }
}
