<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Read;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Compensation\Application\Contract\PromisedWindowReader;

/**
 * Lee la ventana de entrega prometida desde la proyección de shipments (delivery_date + delivery_window_end,
 * "HH:MM"). La zona horaria es la de Bolivia (UTC−4); el caller aplica la tolerancia de la política.
 */
final readonly class DbPromisedWindowReader implements PromisedWindowReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function promisedEndFor(string $shipmentId): ?DateTimeImmutable
    {
        $row = $this->db->table('shipments')
            ->where('id', $shipmentId)
            ->first(['delivery_date', 'delivery_window_end']);
        if ($row === null || !is_string($row->delivery_date)) {
            return null;
        }

        $date = substr($row->delivery_date, 0, 10);
        $end = is_string($row->delivery_window_end) && $row->delivery_window_end !== ''
            ? $row->delivery_window_end
            : '23:59';

        return DateTimeImmutable::createFromFormat('Y-m-d H:i', $date.' '.$end) ?: null;
    }

    public function wasRescheduledByCustomer(string $shipmentId): bool
    {
        // Señal conservadora: sin una marca explícita de reprogramación por el cliente en la proyección, se
        // asume que no lo fue. El reactor de tardía trata esto como "no excluir" (decisión del dueño D2).
        return false;
    }
}
