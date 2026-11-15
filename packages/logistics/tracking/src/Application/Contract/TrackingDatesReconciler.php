<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Puerto de mantenimiento: pone al día dos fechas del seguimiento con los hechos reales de ejecución.
 *
 *  1. `at_store_at` — pedidos con retiro en tienda que el chofer YA había dejado en su tienda destino antes
 *     de que existiera la columna: toma el momento de esa entrega en tienda.
 *  2. `delivered_at` — entregas guardadas con la fecha corrida exactamente el desplazamiento horario de la
 *     app (el hecho venía en UTC y la base lo leyó en la zona de su sesión): vuelve al momento real de la
 *     prueba de entrega. Solo toca las filas con ESE corrimiento exacto; cualquier otra diferencia se deja.
 *
 * IDEMPOTENTE: lo ya corregido deja de cumplir la condición. No emite eventos ni cambia estados.
 */
interface TrackingDatesReconciler
{
    /**
     * Cuenta (o aplica, con `$apply`) las filas de cada caso.
     *
     * @return array{at_store: int, delivered_shifted: int}
     */
    public function run(bool $apply): array;
}
