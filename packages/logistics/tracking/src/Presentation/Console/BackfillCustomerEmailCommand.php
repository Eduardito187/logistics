<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Tracking\Application\Contract\CustomerEmailBackfill;

/**
 * Completa `tracking_shipments.customer_email` en los seguimientos ya entregados (previos a la captura en
 * la proyección), para que entren al proceso de calificación. Idempotente y re-ejecutable; se corre UNA vez
 * tras desplegar la migración (después la proyección lo mantiene sola). `--dry-run` solo cuenta.
 */
final class BackfillCustomerEmailCommand extends Command
{
    protected $signature = 'logistics:tracking:backfill-customer-email
        {--dry-run : Count only, do not write}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Fill customer_email for already delivered shipment trackings (idempotent)';

    public function handle(CustomerEmailBackfill $backfill): int
    {
        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption) ? (int) $chunkOption : 0;
        if ($chunk < 1) {
            $this->error('El tamaño de lote (--chunk) debe ser un entero mayor a cero.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run') === true;
        $result = $backfill->run($dryRun, $chunk);

        if ($dryRun) {
            $this->warn('Simulación (--dry-run): no se escribió nada.');
        }

        $this->line('Candidatos (entregados sin email y con email en el envío): '.$result['candidates']);
        $this->line(($dryRun ? 'Se completarían: ' : 'Completados: ').$result['filled']);
        $this->line('Omitidos por email inválido: '.$result['invalid']);
        $this->info('Listo.');

        return self::SUCCESS;
    }
}
