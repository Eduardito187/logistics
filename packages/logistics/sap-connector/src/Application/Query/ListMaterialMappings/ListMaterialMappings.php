<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Query\ListMaterialMappings;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el mapa de traducción completo (sku propio ↔ coordenada SAP), para administración y para
 * auditar la configuración de la capa anti-corrupción (ADR 002). Sin filtros: devuelve todos.
 */
final readonly class ListMaterialMappings implements Query {}
