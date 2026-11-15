<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Contract;

/**
 * Lectura de los nodos ACTIVOS de la red con su departamento, en primitivos, para armar el catálogo de
 * ciudades. Es un contrato de lectura (lado consulta de CQRS): la implementación consulta la base sin
 * hidratar agregados.
 */
interface CityCatalogReader
{
    /**
     * Nodos activos que tienen departamento cargado, ordenados por código.
     *
     * @return list<array{id: string, code: string, name: string, type: string, department: string}>
     */
    public function activeNodes(): array;
}
