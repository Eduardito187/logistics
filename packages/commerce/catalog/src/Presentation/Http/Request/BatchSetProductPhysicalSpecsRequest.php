<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Request;

use Commerce\Catalog\Application\Command\SetProductPhysicalSpec\SetProductPhysicalSpec;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cuerpo de `POST /integrations/catalog/products/physical-specs` (lote de medidas por SKU).
 *
 * `items` es una lista de 1..500 elementos `{sku, weight_kg?, length_cm?, width_cm?, height_cm?}`. Un
 * error de FORMA en cualquier ítem rechaza el lote completo con 422; los errores por ítem de negocio
 * (SKU inexistente) se informan en la respuesta sin abortar el resto.
 */
final class BatchSetProductPhysicalSpecsRequest extends FormRequest
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
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.PhysicalSpecRules::MAX_BATCH_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.sku' => ['required', 'string', 'max:255'],
            ...PhysicalSpecRules::for('items.*.'),
        ];
    }

    /**
     * Un comando por ítem, en el orden recibido.
     *
     * @return list<SetProductPhysicalSpec>
     */
    public function toCommands(): array
    {
        /** @var array{items: list<array<string, mixed>>} $data */
        $data = $this->validated();

        $commands = [];
        foreach ($data['items'] as $item) {
            $sku = $item['sku'] ?? '';

            $commands[] = new SetProductPhysicalSpec(
                productId: null,
                sku: is_string($sku) ? $sku : '',
                weightKg: PhysicalSpecRules::toFloat($item['weight_kg'] ?? null),
                lengthCm: PhysicalSpecRules::toFloat($item['length_cm'] ?? null),
                widthCm: PhysicalSpecRules::toFloat($item['width_cm'] ?? null),
                heightCm: PhysicalSpecRules::toFloat($item['height_cm'] ?? null),
            );
        }

        return $commands;
    }
}
