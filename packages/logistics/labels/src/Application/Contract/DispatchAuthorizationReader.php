<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Contract;

use Logistics\Labels\Application\ReadModel\DispatchAuthorization;

/**
 * Puerto de lectura de la AUTORIZACIÓN DE DESPACHO de un envío: ¿ya tiene camión y se puede etiquetar?
 *
 * Un envío queda autorizado por CUALQUIERA de las dos vías por las que la operación le pone camión:
 *
 *  1. Planificación: tiene parada en un plan PUBLICADO.
 *  2. Asignación directa desde el andén (flujo vigente: "asignar el vehículo ES preparar"): tiene una
 *     entrega abierta en ejecución o una parada en la ruta de un camión, aunque nunca haya pasado por un
 *     plan. En esta vía se respeta la guarda de aptitud: si el camión quedó NO APTO, no se autoriza.
 *
 * Etiquetas no conoce los dominios de planificación, ejecución ni flota: la implementación (Infrastructure)
 * lee sus tablas en solo lectura y es tolerante (una fuente caída no rompe la pantalla).
 */
interface DispatchAuthorizationReader
{
    public function forShipment(string $shipmentId): DispatchAuthorization;
}
