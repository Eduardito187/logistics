<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Fulfillment;

use Logistics\Promise\Domain\Contract\FulfillmentLeadTimeProvider;
use Logistics\Promise\Domain\ValueObject\LeadTime;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Implementación por DEFAULT del lead time adicional: un valor fijo en días por modo de fulfillment.
 *
 * Es el piso conservador del cálculo (docs/marketplace-dropship-roadmap.md §8.1):
 *  - OWNED: 0 días (retail intacto).
 *  - FBD: lead de recepción (el seller deja el producto en el almacén central y Dismac lo etiqueta).
 *  - DROPSHIP: lead de despacho del propio seller.
 *
 * Los días se inyectan desde el ServiceProvider (que los lee de `config('promise.fulfillment_lead_days.*')`
 * con un fallback conservador), así no se hardcodea política de negocio en el dominio.
 *
 * GANCHO (pendiente de los contextos inbound/inventory): para FBD, el lead solo aplica cuando NO hay stock
 * ya recibido del seller. Mientras `inventory` no exista, se asume el peor caso (sin stock → se suma el
 * lead), que es lo conservador para una promesa. Cuando exista Available-To-Promise por dueño, este puerto
 * debe consultar el stock y devolver `LeadTime::none()` para FBD con stock disponible.
 */
final readonly class ConfigurableFulfillmentLeadTimeProvider implements FulfillmentLeadTimeProvider
{
    public function __construct(
        private int $fbdLeadDays,
        private int $dropshipLeadDays,
    ) {}

    public function leadTimeFor(Ownership $ownership): LeadTime
    {
        return match ($ownership->mode) {
            FulfillmentMode::Owned => LeadTime::none(),
            FulfillmentMode::Fbd => LeadTime::ofDays($this->fbdLeadDays),
            FulfillmentMode::Dropship => LeadTime::ofDays($this->dropshipLeadDays),
        };
    }
}
