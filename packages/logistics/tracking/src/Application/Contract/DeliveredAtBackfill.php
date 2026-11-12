<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Puerto de mantenimiento: completa `delivered_at` en los seguimientos YA entregados antes de que existiera
 * la columna (o cuya proyección no la fijó). IDEMPOTENTE: solo toca filas entregadas/devueltas con
 * `delivered_at` vacío, así que re-ejecutarlo no cambia nada de lo ya completado.
 *
 * Fuentes, de la más fiel a la menos (cada paso solo completa lo que el anterior dejó vacío):
 *  1. `execution_deliveries` (solo lectura): `pod_captured_at` o, sin él, `updated_at` de la entrega
 *     en estado DELIVERED — es el momento real del hecho registrado por el chofer.
 *  2. Outbox `execution.shipment_delivered`: `created_at` del primer mensaje del envío (se graba en la
 *     misma transacción que la entrega).
 *  3. Outbox `tracking.status_changed` a DELIVERED: historial de estados del propio seguimiento.
 *  4. Último recurso (opcional): `tracking_shipments.updated_at`. Es una APROXIMACIÓN (cambia con cualquier
 *     guardado posterior, p. ej. una devolución), por eso se informa por separado y se puede omitir.
 */
interface DeliveredAtBackfill
{
    /**
     * Ejecuta el backfill y devuelve cuántas filas completó cada fuente.
     *
     * @return array{execution: int, outbox_delivered: int, status_history: int, updated_at: int}
     */
    public function run(bool $useUpdatedAtFallback = true): array;
}
