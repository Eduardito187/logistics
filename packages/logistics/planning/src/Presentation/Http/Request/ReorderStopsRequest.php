<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;

/**
 * Valida el reordenamiento humano de la secuencia de paradas de un plan.
 *
 * La lista debe traer exactamente los envíos del plan en el nuevo orden; que coincidan con el plan es
 * una invariante del dominio y se valida en el agregado, no acá.
 */
final class ReorderStopsRequest extends FormRequest
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
            'ordered_shipment_ids' => ['required', 'array', 'min:1'],
            'ordered_shipment_ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    public function toCommand(string $planId): ReorderStops
    {
        /** @var array{ordered_shipment_ids: list<string>} $data */
        $data = $this->validated();

        return new ReorderStops($planId, array_values($data['ordered_shipment_ids']));
    }
}
