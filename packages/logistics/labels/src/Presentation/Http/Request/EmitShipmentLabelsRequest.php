<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Labels\Application\Dto\OrderLineInput;

/**
 * Validación de la emisión de etiquetas de un pedido (puerta app).
 *
 * Dos modos sobre el MISMO endpoint (POST .../labels):
 *  - LOTE EXPLÍCITO: el cuerpo trae origen, destino y líneas (la posición fija el número de línea). Lo usa
 *    el integrador/worker que ya conoce el detalle.
 *  - POR ORDEN: cuerpo vacío (o solo `origin` como escape hatch). El backend deriva origen/destino/líneas
 *    del envío y del plan publicado y emite lo autorizado y pending. Por eso todos los campos son OPCIONALES
 *    aquí; `hasExplicitBatch()` decide el modo. La pre-emisión (worker) mantiene su contrato estricto en
 *    PreEmitOrderLabelsRequest.
 */
class EmitShipmentLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización fina (token de usuario + dispositivo, roles) la aplica el middleware de la
        // puerta app que arma el integrador; aquí solo se valida la forma del input.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin' => ['sometimes', 'nullable', 'string', 'max:64'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:64'],
            'lines' => ['sometimes', 'nullable', 'array'],
            'lines.*.sku' => ['required_with:lines', 'string', 'max:64'],
            'lines.*.name' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.pieces_per_unit' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * ¿El cuerpo trae un LOTE EXPLÍCITO (origen + destino + al menos una línea)? Si no, es emisión por orden.
     */
    public function hasExplicitBatch(): bool
    {
        return $this->origin() !== '' && $this->destination() !== '' && $this->orderLines() !== [];
    }

    /**
     * Traduce el cuerpo validado a los DTOs de línea del contexto, en orden.
     *
     * @return list<OrderLineInput>
     */
    public function orderLines(): array
    {
        /** @var list<array{sku: string, name: string, quantity: int, pieces_per_unit?: int}>|null $lines */
        $lines = $this->validated('lines');
        if (!is_array($lines)) {
            return [];
        }

        return array_map(
            static fn (array $line): OrderLineInput => new OrderLineInput(
                sku: $line['sku'],
                name: $line['name'],
                quantity: $line['quantity'],
                piecesPerUnit: $line['pieces_per_unit'] ?? 1,
            ),
            array_values($lines),
        );
    }

    public function origin(): string
    {
        $origin = $this->validated('origin');

        return is_string($origin) ? $origin : '';
    }

    public function destination(): string
    {
        $destination = $this->validated('destination');

        return is_string($destination) ? $destination : '';
    }
}
