<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura para saber si un envío es un TRASPASO interno de mercadería y a qué nodo va.
 *
 * La ejecución la usa para cerrar la entrega con la «entrega en tienda»: un traspaso no tiene cliente que
 * firme una prueba de entrega, así que dejarlo en SU nodo destino es la entrega. La modalidad la decide el
 * contexto de envíos (fuente interna + nodo destino); acá solo se lee el resultado, en primitivos.
 */
interface TransferDestinationReader
{
    /**
     * Nodo destino del envío si es un traspaso interno; null si es un pedido de cliente (domicilio o retiro
     * en tienda), si no tiene nodo destino o si el envío no existe.
     */
    public function transferDestinationNodeId(string $shipmentId): ?string;
}
