<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use Logistics\Promise\Domain\ValueObject\LeadTime;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Puerto que resuelve el lead time ADICIONAL de una promesa según el modo de fulfillment de la propiedad.
 *
 * Regla de negocio (docs/marketplace-dropship-roadmap.md §8.1):
 *  - OWNED (retail): sin tiempo adicional → `LeadTime::none()` (el flujo retail no cambia).
 *  - FBD sin stock disponible: suma el lead de recepción del seller en el almacén central.
 *  - DROPSHIP: suma el lead de despacho del propio seller.
 *
 * El origen concreto del valor (default por modo en config, o por seller leído de su tabla) vive en
 * Infrastructure; el dominio solo conoce este contrato. Así promise no agrega dependencia composer a
 * `sellers`/`inventory`: el adaptador lee las tablas de solo consulta.
 */
interface FulfillmentLeadTimeProvider
{
    public function leadTimeFor(Ownership $ownership): LeadTime;
}
