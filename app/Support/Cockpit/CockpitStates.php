<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

/**
 * Estados TERMINALES compartidos por los servicios de lectura del cockpit. Viven en un holder propio (no
 * en el trait {@see Concerns\ReadsCockpitAggregates}) porque no todos los consumidores del trait usan
 * ambos conjuntos: una constante privada de trait se copia por clase y PHPStan la marcaría como no usada
 * en quien no la referencia. Como constantes públicas de una clase dedicada el uso es inequívoco.
 */
final class CockpitStates
{
    /** Estados TERMINALES de un ENVÍO (shipments): ya no cuenta como activo en la red. */
    public const SHIPMENT_TERMINAL = ['DELIVERED', 'FAILED', 'CANCELLED'];

    /** Estados TERMINALES de una ENTREGA en ejecución (DeliveryStatus no tiene CANCELLED). */
    public const DELIVERY_TERMINAL = ['DELIVERED', 'FAILED'];
}
