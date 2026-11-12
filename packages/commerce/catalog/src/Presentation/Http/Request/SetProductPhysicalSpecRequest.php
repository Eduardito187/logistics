<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Request;

use Commerce\Catalog\Application\Command\SetProductPhysicalSpec\SetProductPhysicalSpec;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de `PUT /integrations/catalog/products/{sku}/physical-spec` (Magento/POS envía medidas por SKU).
 *
 * Semántica PUT: los campos omitidos o null quedan vacíos en la ficha. Al menos uno debe venir con
 * valor. La autenticación del integrador la resuelve el middleware de la puerta.
 */
final class SetProductPhysicalSpecRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PhysicalSpecRules::for();
    }

    /**
     * Arma el comando a partir del payload validado y el SKU de la ruta.
     */
    public function toCommand(string $sku): SetProductPhysicalSpec
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new SetProductPhysicalSpec(
            productId: null,
            sku: $sku,
            weightKg: PhysicalSpecRules::toFloat($data['weight_kg'] ?? null),
            lengthCm: PhysicalSpecRules::toFloat($data['length_cm'] ?? null),
            widthCm: PhysicalSpecRules::toFloat($data['width_cm'] ?? null),
            heightCm: PhysicalSpecRules::toFloat($data['height_cm'] ?? null),
        );
    }
}
