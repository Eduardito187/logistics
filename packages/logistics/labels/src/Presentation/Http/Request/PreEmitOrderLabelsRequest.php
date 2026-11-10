<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Request;

/**
 * Validación de la pre-emisión de etiquetas (puerta integrations).
 *
 * La dispara el worker/integrador al publicarse un plan y SIEMPRE trae el lote completo, así que mantiene
 * el contrato ESTRICTO (origen, destino y al menos una línea requeridos), a diferencia de la emisión por
 * la app, que admite cuerpo vacío (emisión por orden). El handler es idempotente si el pedido ya tiene
 * etiquetas.
 */
final class PreEmitOrderLabelsRequest extends EmitShipmentLabelsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'max:64'],
            'destination' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.pieces_per_unit' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
