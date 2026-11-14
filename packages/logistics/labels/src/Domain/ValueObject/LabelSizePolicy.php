<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Política de tamaño de etiqueta vigente: el perfil por defecto, las reglas por SKU y el umbral de medidas
 * con el que un producto se considera «chico». Es la configuración que consume {@see LabelSizeResolver}
 * para decidir en qué perfil se imprime cada producto.
 *
 * Decisiones del dueño materializadas como defaults (roadmap §7):
 *  - perfil por defecto = Standard (nada cambia si no hay regla ni medida);
 *  - perfil chico = Lite 50 × 25 (recomendado);
 *  - un producto es «chico» si su lado más largo ≤ `smallMaxLongestSideCm` y (si se conoce) su peso ≤
 *    `smallMaxWeightKg`. Umbrales por defecto: 25 cm y 2 kg (celular/accesorio típico).
 */
final readonly class LabelSizePolicy
{
    /**
     * @param list<LabelSizeRule> $rules reglas por SKU, en orden de prioridad (la primera que matchea gana)
     */
    public function __construct(
        public LabelSizeProfile $defaultProfile,
        public LabelSizeProfile $smallProfile,
        public array $rules,
        public ?float $smallMaxLongestSideCm,
        public ?float $smallMaxWeightKg,
    ) {}

    /** Política por defecto cuando no hay settings ni config: Standard, chico = Lite, umbrales 25 cm / 2 kg. */
    public static function default(): self
    {
        return new self(
            LabelSizeProfile::DEFAULT,
            LabelSizeProfile::RECOMMENDED_SMALL,
            [],
            25.0,
            2.0,
        );
    }

    /**
     * Primera regla por SKU que matchea, o null si ninguna aplica.
     */
    public function ruleFor(string $sku): ?LabelSizeRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($sku)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * True si las medidas dadas califican al producto como «chico» según los umbrales de la política. Un
     * producto sin dimensiones NO califica (se degrada a Standard): nunca se adivina el tamaño.
     */
    public function qualifiesAsSmall(ProductMeasures $measures): bool
    {
        if ($this->smallMaxLongestSideCm === null) {
            return false;
        }

        $longest = $measures->longestSideCm();
        if ($longest === null || $longest > $this->smallMaxLongestSideCm) {
            return false;
        }

        // El peso solo descalifica si se conoce y supera el umbral; peso desconocido no bloquea.
        if ($this->smallMaxWeightKg !== null
            && $measures->weightKg !== null
            && $measures->weightKg > $this->smallMaxWeightKg) {
            return false;
        }

        return true;
    }
}
