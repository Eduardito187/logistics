<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Una regla configurable de tamaño de etiqueta: «los productos cuyo SKU matchea X van en el perfil P».
 * Las reglas se evalúan en orden; la primera que matchea gana (ver {@see LabelSizeResolver}). Nunca se
 * decide por propietario/seller: la regla depende del PRODUCTO (roadmap §4.4).
 */
final readonly class LabelSizeRule
{
    public function __construct(
        public LabelSizeMatch $match,
        public string $value,
        public LabelSizeProfile $profile,
    ) {}

    /**
     * Construye una regla desde primitivos (settings/config), tolerante: valores desconocidos caen a sus
     * defaults. Devuelve null si el valor a matchear viene vacío (regla sin sentido).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $value = $data['value'] ?? null;
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        return new self(
            LabelSizeMatch::fromValue($data['match'] ?? null),
            $value,
            LabelSizeProfile::fromValue($data['profile'] ?? null),
        );
    }

    /** True si esta regla aplica al SKU dado (comparación insensible a mayúsculas y espacios). */
    public function matches(string $sku): bool
    {
        $sku = strtoupper(trim($sku));
        $value = strtoupper(trim($this->value));
        if ($sku === '' || $value === '') {
            return false;
        }

        return match ($this->match) {
            LabelSizeMatch::SkuExact => $sku === $value,
            LabelSizeMatch::SkuPrefix => str_starts_with($sku, $value),
        };
    }

    /**
     * @return array{match: string, value: string, profile: string}
     */
    public function toArray(): array
    {
        return [
            'match' => $this->match->value,
            'value' => $this->value,
            'profile' => $this->profile->value,
        ];
    }
}
