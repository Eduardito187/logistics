<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

use Commerce\Pricing\Domain\Exception\PricingException;

/**
 * Política tributaria aplicada a un precio al público. Value object inmutable.
 *
 * Modela los dos impuestos de Bolivia relevantes para el precio de venta:
 *
 * - **IVA (Impuesto al Valor Agregado), 13 %, "por dentro"**: el precio al público YA incluye el IVA
 *   (patrón boliviano). Dado un precio bruto, el IVA es `round(bruto * tasa)` (no se divide: la tasa es
 *   nominal sobre el precio final) y el neto es `bruto − IVA`. Para 100,00 Bs → IVA 13,00 / neto 87,00.
 * - **IT (Impuesto a las Transacciones), 3 %**: impuesto adicional sobre el bruto, `round(bruto * tasa)`.
 *   No forma parte de la descomposición del precio (no se descuenta del neto); se expone en el desglose.
 *
 * Las tasas se guardan en **basis points** (1 bp = 0,01 %) como enteros para evitar flotantes: IVA
 * 1300 bp = 13 %, IT 300 bp = 3 %. El redondeo es a centavos (medio hacia arriba). Son configurables,
 * pero `bolivia()` trae los defaults del país.
 */
final readonly class TaxPolicy
{
    public const BASIS_POINTS_DENOMINATOR = 10000;

    public const DEFAULT_IVA_BASIS_POINTS = 1300;

    public const DEFAULT_IT_BASIS_POINTS = 300;

    private function __construct(
        public int $ivaBasisPoints,
        public int $itBasisPoints,
    ) {}

    public static function of(int $ivaBasisPoints, int $itBasisPoints): self
    {
        self::assertRate('IVA', $ivaBasisPoints);
        self::assertRate('IT', $itBasisPoints);

        return new self($ivaBasisPoints, $itBasisPoints);
    }

    /**
     * Política por defecto de Bolivia: IVA 13 % (por dentro) + IT 3 %.
     */
    public static function bolivia(): self
    {
        return new self(self::DEFAULT_IVA_BASIS_POINTS, self::DEFAULT_IT_BASIS_POINTS);
    }

    /**
     * IVA contenido en un precio bruto (por dentro): round(bruto * tasa_iva).
     */
    public function ivaOn(Money $gross): Money
    {
        return Money::of($this->applyRate($gross->cents, $this->ivaBasisPoints), $gross->currency);
    }

    /**
     * Neto de un precio bruto: bruto − IVA.
     */
    public function netOf(Money $gross): Money
    {
        return $gross->subtract($this->ivaOn($gross));
    }

    /**
     * IT (Impuesto a las Transacciones) sobre un precio bruto: round(bruto * tasa_it).
     */
    public function transactionTaxOn(Money $gross): Money
    {
        return Money::of($this->applyRate($gross->cents, $this->itBasisPoints), $gross->currency);
    }

    /**
     * Desglose tributario primitivo de un precio bruto, para ReadModels/API.
     *
     * @return array{iva_cents: int, iva_basis_points: int, it_cents: int, it_basis_points: int}
     */
    public function breakdown(Money $gross): array
    {
        return [
            'iva_cents' => $this->ivaOn($gross)->cents,
            'iva_basis_points' => $this->ivaBasisPoints,
            'it_cents' => $this->transactionTaxOn($gross)->cents,
            'it_basis_points' => $this->itBasisPoints,
        ];
    }

    /**
     * Aplica una tasa en basis points a un monto en centavos, redondeando a centavos (medio hacia arriba).
     */
    private function applyRate(int $cents, int $basisPoints): int
    {
        return (int) round($cents * $basisPoints / self::BASIS_POINTS_DENOMINATOR);
    }

    private static function assertRate(string $name, int $basisPoints): void
    {
        if ($basisPoints < 0 || $basisPoints > self::BASIS_POINTS_DENOMINATOR) {
            throw PricingException::because(
                "La tasa de {$name} debe estar entre 0 y ".self::BASIS_POINTS_DENOMINATOR." basis points: {$basisPoints}.",
            );
        }
    }
}
