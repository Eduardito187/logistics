<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Labels\Application\Dto\OrderLineInput;

/**
 * Validación de la emisión de etiquetas de un pedido (puerta app).
 *
 * El número de pedido llega por la ruta; el cuerpo trae origen, destino y las líneas. La posición de
 * cada línea fija su número de línea (el handler respeta el orden del array).
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
            'origin' => ['required', 'string', 'max:64'],
            'destination' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:64'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.pieces_per_unit' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Traduce el cuerpo validado a los DTOs de línea del contexto, en orden.
     *
     * @return list<OrderLineInput>
     */
    public function orderLines(): array
    {
        /** @var list<array{sku: string, name: string, quantity: int, pieces_per_unit?: int}> $lines */
        $lines = $this->validated('lines');

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
        return (string) $this->validated('origin');
    }

    public function destination(): string
    {
        return (string) $this->validated('destination');
    }
}
