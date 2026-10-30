<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\ValueObject;

use Commerce\Invoicing\Domain\Exception\InvoicingException;

/**
 * Descomposición del IVA boliviano (13 %) "por dentro" de un total bruto.
 *
 * En Bolivia el precio al público **ya incluye** el IVA (está contenido en el total). Para facturar hay
 * que separar la base imponible (neto) del impuesto (IVA) a partir del bruto:
 *
 *   neto = round(bruto / 1.13)      (la tasa es 13 %, así que 1 + 0,13 = 1,13)
 *   iva  = bruto − neto
 *
 * Regla de redondeo (documentada y consistente): se redondea SOLO el neto a centavos (medio hacia
 * arriba, `round`) y el IVA se obtiene por diferencia. Así se garantiza siempre la identidad
 * `neto + iva === bruto` (no se pierde ni se inventa un centavo por redondear ambos lados).
 *
 * Coherente con el `TaxPolicy` de `commerce/pricing`, pero este contexto NO depende de pricing: replica
 * el cálculo (acoplamiento cero entre contextos, patrón "VO por contexto" de la skill). Para 113,00 Bs
 * de bruto → neto 100,00 / IVA 13,00.
 */
final readonly class BolivianTax
{
    /** Tasa del IVA en Bolivia, como divisor "por dentro" (1 + 0,13). */
    public const GROSS_DIVISOR = 1.13;

    private function __construct(
        public int $netCents,
        public int $ivaCents,
    ) {}

    /**
     * Descompone un total bruto (IVA incluido) en su base imponible (neto) y su IVA.
     *
     * @param  int                       $grossCents total bruto en centavos (>= 0)
     * @return array{net: int, iva: int}
     */
    public static function fromGross(int $grossCents): array
    {
        $tax = self::of($grossCents);

        return ['net' => $tax->netCents, 'iva' => $tax->ivaCents];
    }

    /**
     * Descompone un total bruto y devuelve el value object (neto + IVA).
     */
    public static function of(int $grossCents): self
    {
        if ($grossCents < 0) {
            throw InvoicingException::because("El total bruto no puede ser negativo: {$grossCents}.");
        }

        // Solo se redondea el neto; el IVA es el resto, para que neto + iva === bruto siempre.
        $net = (int) round($grossCents / self::GROSS_DIVISOR);
        $iva = $grossCents - $net;

        return new self($net, $iva);
    }
}
