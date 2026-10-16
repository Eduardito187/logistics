<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

/**
 * Dimensión de propiedad de un traspaso de stock hacia SAP (ADR 017, roadmap marketplace §6.4).
 *
 * Distingue el stock propio de Dismac del stock de un seller (consignación):
 *
 * - `Owned`: stock propio (retail, comportamiento histórico). Se postea a SAP como material propio de
 *   Dismac, exactamente como hoy.
 * - `Consignment`: stock de un seller. Es un movimiento puramente logístico: NO cambia la titularidad
 *   propia de Dismac en SAP, por lo que NUNCA se postea como material propio (regla dura del roadmap).
 *   Según la política contable se omite el posteo de propiedad o, a futuro, va a un almacén de
 *   consignación con un puerto SAP dedicado.
 *
 * Es el reflejo, dentro de la frontera del conector (ADR 002), del `FulfillmentMode` transversal de
 * `shared`: `Owned` ≙ retail (`owned`); `Consignment` ≙ marketplace del seller (`fbd`/`dropship`). Se
 * replica acá para que el conector no dependa de ningún otro contexto (acoplamiento cero).
 */
enum TransferKind: string
{
    /** Stock propio de Dismac: se postea a SAP como material propio (comportamiento retail histórico). */
    case Owned = 'owned';

    /** Stock de un seller (consignación): movimiento logístico sin posteo de propiedad propia a SAP. */
    case Consignment = 'consignment';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owned => 'Owned (retail)',
            self::Consignment => 'Consignment',
        };
    }

    /**
     * Regla dura (roadmap §6.4): solo el stock propio postea su titularidad a SAP como material de
     * Dismac. La consignación NUNCA lo hace.
     */
    public function postsOwnershipToSap(): bool
    {
        return $this === self::Owned;
    }

    /**
     * ¿Exige un dueño (seller)? La consignación sí; el stock propio no (es de Dismac).
     */
    public function requiresOwner(): bool
    {
        return $this === self::Consignment;
    }
}
