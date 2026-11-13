<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Perfil de TAMAÑO de una etiqueta: cómo se dibuja físicamente una etiqueta que ya existe (ADR 004,
 * roadmap 03). El tamaño es un atributo de PRESENTACIÓN, no de identidad: la misma etiqueta (mismo
 * `label_id`, mismo QR cuando lleva el JSON) se puede reimprimir en cualquier perfil cuantas veces haga
 * falta. Elegir un perfil NUNCA re-emite ni cambia el `label_id`.
 *
 * Catálogo cerrado en código (decisión de diseño: medidas controladas, no texto libre). El DPI NO es del
 * perfil: es de la impresora; el renderer calcula los dots = mm × dpi / 25,4.
 *
 *  - `Standard`   100 × 75 mm: formato actual de la Zebra ZQ630 Plus (4", móvil). Lleva el QR con el JSON
 *                 firmado completo + Code128 con el `label_id` + texto largo. Es el DEFAULT global.
 *  - `Lite`       50 × 25 mm: para productos chicos (celulares, accesorios, cajas chicas). Lleva un QR con
 *                 el `label_id` DESNUDO (mismo valor que ya lleva hoy el Code128 del Standard; NO toca el
 *                 contrato del JSON firmado) + texto corto. Sin Code128 (no cabe). Perfil chico RECOMENDADO.
 *  - `Lite40x30`  40 × 30 mm: variante para rollos de escritorio de 40 mm. Data Matrix con el `label_id`.
 *  - `Micro`      30 × 15 mm (solo 300 dpi): joyería/cables. Data Matrix con el `label_id`; por tamaño solo
 *                 lleva SKU + últimos 6 del id (excepción a «nombre + SKU», decisión del dueño §7.4).
 */
enum LabelSizeProfile: string
{
    case Standard = 'standard';

    case Lite = 'lite';

    case Lite40x30 = 'lite_40x30';

    case Micro = 'micro';

    /** Perfil usado cuando no hay regla ni medida que decida otra cosa: el Standard de 4". */
    public const LabelSizeProfile DEFAULT = self::Standard;

    /** Perfil chico recomendado por el roadmap como default para productos pequeños. */
    public const LabelSizeProfile RECOMMENDED_SMALL = self::Lite;

    /**
     * Interpreta un valor primitivo (de settings, config, query param) de forma tolerante: cualquier valor
     * desconocido, vacío o de tipo inesperado cae al DEFAULT, para que la etiqueta siempre tenga un perfil
     * con el que imprimirse.
     */
    public static function fromValue(mixed $value): self
    {
        if (!is_string($value)) {
            return self::DEFAULT;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::DEFAULT;
    }

    /** Ancho del medio en milímetros. */
    public function widthMm(): int
    {
        return match ($this) {
            self::Standard => 100,
            self::Lite => 50,
            self::Lite40x30 => 40,
            self::Micro => 30,
        };
    }

    /** Alto del medio en milímetros. */
    public function heightMm(): int
    {
        return match ($this) {
            self::Standard => 75,
            self::Lite => 25,
            self::Lite40x30 => 30,
            self::Micro => 15,
        };
    }

    /** Nombre legible en inglés para el admin/API (regla 5: texto visible en inglés). */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard 100 x 75 mm',
            self::Lite => 'Lite 50 x 25 mm',
            self::Lite40x30 => 'Lite 40 x 30 mm',
            self::Micro => 'Micro 30 x 15 mm',
        };
    }

    /** Dots de ancho a un DPI dado (203 u 300). dots = mm × dpi / 25,4. */
    public function widthDots(int $dpi): int
    {
        return self::dots($this->widthMm(), $dpi);
    }

    /** Dots de alto a un DPI dado. */
    public function heightDots(int $dpi): int
    {
        return self::dots($this->heightMm(), $dpi);
    }

    /** El Standard de 4" (formato histórico). */
    public function isStandard(): bool
    {
        return $this === self::Standard;
    }

    /**
     * True si el perfil dibuja el QR con el JSON FIRMADO completo (contrato con la app). Solo el Standard:
     * los perfiles chicos llevan el `label_id` desnudo, que NO es contrato nuevo (ya existe en el Code128).
     */
    public function carriesSignedQr(): bool
    {
        return $this === self::Standard;
    }

    /** True si el perfil dibuja además el Code128. Solo el Standard (en chico no cabe, roadmap §4.2). */
    public function carriesBarcode(): bool
    {
        return $this === self::Standard;
    }

    /**
     * True si el perfil muestra el nombre del producto junto al SKU. Todos menos Micro (en 30 × 15 mm no
     * entra; lleva solo SKU + últimos 6 del id, excepción aprobada por el dueño §7.4).
     */
    public function showsProductName(): bool
    {
        return $this !== self::Micro;
    }

    private static function dots(int $mm, int $dpi): int
    {
        return (int) round($mm * $dpi / 25.4);
    }
}
