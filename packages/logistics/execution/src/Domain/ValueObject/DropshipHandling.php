<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Cómo ejecuta Dismac un envío DROPSHIP (el seller despacha directamente). Es la rama de ejecución que
 * NO existe para OWNED/FBD: define cuánto de la operación hace Dismac y, por ende, qué escaneos propios
 * aplican. Ver docs/marketplace-dropship-roadmap.md §8.2.
 *
 * - TrackOnly:          Dismac solo da visibilidad; el seller despacha con su propio transporte. No hay
 *                       escaneos propios; la entrega se cierra por CONFIRMACIÓN del seller.
 * - ThirdPartyCarrier:  Dismac coordina un transportista tercero (contexto carriers). Tampoco hay
 *                       escaneos propios; la entrega se cierra por confirmación (seller/tercero).
 * - LastMile:           Dismac hace la última milla del producto del seller. En este caso SÍ aplican los
 *                       escaneos propios (preparación/carga/descarga/POD), igual que un envío OWNED.
 */
enum DropshipHandling: string
{
    /** Dismac solo trackea; el seller despacha. Sin escaneos propios. */
    case TrackOnly = 'track_only';

    /** Dismac coordina transportista tercero. Sin escaneos propios. */
    case ThirdPartyCarrier = 'third_party_carrier';

    /** Dismac hace la última milla. Escaneos propios habilitados (como OWNED). */
    case LastMile = 'last_mile';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::TrackOnly => 'Track only',
            self::ThirdPartyCarrier => 'Third-party carrier',
            self::LastMile => 'Last mile by Dismac',
        };
    }

    /**
     * ¿Aplican los escaneos propios de Dismac (preparación/carga/descarga/POD)? Solo cuando Dismac hace
     * la última milla; en los demás modos el flujo de terreno propio NO corre.
     */
    public function usesOwnScans(): bool
    {
        return $this === self::LastMile;
    }

    /**
     * ¿La entrega se cierra por confirmación del seller/tercero (en vez de por POD propio)?
     */
    public function isSellerConfirmed(): bool
    {
        return !$this->usesOwnScans();
    }
}
