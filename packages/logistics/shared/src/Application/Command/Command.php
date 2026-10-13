<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Command;

/**
 * Marca un objeto como comando: una intención de cambiar estado ("registrar nodo", "recibir pedido").
 *
 * Un comando es un DTO inmutable. No devuelve datos de lectura; a lo sumo, el id generado.
 */
interface Command {}
