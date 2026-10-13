<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Query;

/**
 * Marca un objeto como consulta: pide datos sin cambiar estado ("listar nodos", "ver envío").
 *
 * Las consultas pueden saltarse el dominio y leer modelos de lectura optimizados (CQRS).
 */
interface Query {}
