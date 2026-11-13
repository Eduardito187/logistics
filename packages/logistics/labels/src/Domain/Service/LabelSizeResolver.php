<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Service;

use Logistics\Labels\Domain\ValueObject\LabelSizePolicy;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\ProductMeasures;

/**
 * Decide en qué perfil de tamaño se imprime una etiqueta. Servicio de dominio PURO: recibe primitivos y
 * VOs, no conoce Laravel ni la base. El orden de precedencia (el primero que aplica gana) sigue el
 * roadmap §4.4:
 *
 *  1. Elección AL IMPRIMIR (admin/app pide explícitamente un perfil) → manda.
 *  2. Override por línea guardado (perfil sugerido fijado a mano para ese producto del pedido).
 *  3. Reglas configurables por SKU (exacto/prefijo), en orden.
 *  4. Medidas del producto: si es «chico» según la política, usa el perfil chico.
 *  5. Perfil por defecto de la política (Standard).
 *
 * Sin medidas y sin regla, siempre degrada al perfil por defecto: nunca se adivina un tamaño chico.
 */
final readonly class LabelSizeResolver
{
    public function resolve(
        string $sku,
        ?ProductMeasures $measures,
        LabelSizePolicy $policy,
        ?LabelSizeProfile $printOverride = null,
        ?LabelSizeProfile $lineOverride = null,
    ): LabelSizeProfile {
        // 1. Elección explícita al imprimir (reimpresión permitida, mismo id).
        if ($printOverride !== null) {
            return $printOverride;
        }

        // 2. Override por línea guardado para ese producto del pedido.
        if ($lineOverride !== null) {
            return $lineOverride;
        }

        // 3. Reglas por SKU configuradas por operación.
        $rule = $policy->ruleFor($sku);
        if ($rule !== null) {
            return $rule->profile;
        }

        // 4. Medidas del producto: chico → perfil chico de la política.
        if ($measures !== null && !$measures->isEmpty() && $policy->qualifiesAsSmall($measures)) {
            return $policy->smallProfile;
        }

        // 5. Perfil por defecto (Standard).
        return $policy->defaultProfile;
    }
}
