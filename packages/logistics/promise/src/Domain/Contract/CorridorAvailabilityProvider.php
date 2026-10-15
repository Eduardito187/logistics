<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

/**
 * Puerto hacia el contexto de red: ¿hay un corredor operativo que llegue a la zona?
 *
 * El dominio de promise define QUÉ necesita (saber si existe un corredor activo hacia el nodo que sirve
 * la zona); CÓMO se resuelve —consultando los nodos y tramos de `logistics/network`— es decisión de
 * Infrastructure. Sin corredor no se puede prometer ninguna fecha, por más cupo y cobertura que haya.
 */
interface CorridorAvailabilityProvider
{
    /**
     * ¿La zona es alcanzable por un corredor operativo de la red? False si no hay nodo que la sirva, si
     * ese nodo no está activo o si ningún tramo lo conecta.
     */
    public function servesZone(string $zoneId): bool;
}
