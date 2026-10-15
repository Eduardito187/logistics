<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocuments;
use Logistics\Fleet\Application\Command\AlertExpiringDocuments\AlertExpiringDocumentsHandler;

/**
 * Barrido programado de documentos de flota por vencer / vencidos (P1-FLT-04). Lo corre el scheduler
 * (ver routes/console.php, diario a las 07:00) o se invoca a mano. No hace I/O por sí mismo: delega en
 * el handler, que emite la alerta al outbox. Imprime el resumen de la corrida para poder monitorearlo.
 */
final class AlertExpiringDocumentsCommand extends Command
{
    protected $signature = 'logistics:fleet:document-alerts';

    protected $description = 'Scan fleet documents for upcoming/expired expiries and alert the supervisor via the outbox';

    public function handle(AlertExpiringDocumentsHandler $handler): int
    {
        $handler->handle(new AlertExpiringDocuments);

        $this->info(sprintf(
            'Fleet document expiry scan finished: %d in window (%d expired, %d due within 7 days, %d within 30 days); alert %s.',
            $handler->lastTotal(),
            $handler->lastExpired(),
            $handler->lastDueSoon(),
            $handler->lastUpcoming(),
            $handler->lastEmitted() ? 'emitted' : 'skipped (nothing to report)',
        ));

        return self::SUCCESS;
    }
}
