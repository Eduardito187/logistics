<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el listado de municipios (id + etiqueta legible + departamento) para poblar selects buscables
 * del admin y respuestas de API, p. ej. al elegir el municipio sobre el que se activan cupos.
 */
final readonly class ListMunicipalities implements Query {}
