<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Contract;

/**
 * Puerto de lectura read-only del pago iniciado de una orden, para calcular la ventana de expiración.
 *
 * Orders NO depende del dominio de `payments`: la implementación lee las columnas de la tabla de pagos
 * con el query builder (igual que el patrón de enriquecimiento cruzado de ejecución). Devuelve solo lo
 * imprescindible para la expiración: el método (define la ventana) y la fecha de creación del pago.
 */
interface PaymentScheduleReader
{
    /**
     * Último pago PENDIENTE de la orden (método + fecha de creación), o null si la orden no inició pago.
     *
     * @return array{method: string, created_at: string}|null
     */
    public function latestPendingForOrder(string $orderId): ?array;
}
