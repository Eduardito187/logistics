<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Puerto de mantenimiento: completa `tracking_shipments.customer_email` en los seguimientos YA entregados
 * antes de que la proyección lo capturara, copiándolo del lado lectura de `shipments` (normalizado y
 * validado con la misma regla que la proyección). IDEMPOTENTE: solo toca filas DELIVERED con email vacío y
 * cada UPDATE vuelve a exigir `customer_email IS NULL`. No graba eventos de outbox.
 */
interface CustomerEmailBackfill
{
    /**
     * Recorre los candidatos en lotes por id. En `dryRun` solo cuenta, sin escribir.
     *
     * @return array{candidates: int, filled: int, invalid: int}
     */
    public function run(bool $dryRun, int $chunk): array;
}
