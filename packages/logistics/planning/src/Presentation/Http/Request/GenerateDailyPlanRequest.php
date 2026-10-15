<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Planning\Application\Command\GenerateDailyPlan\GenerateDailyPlan;

/**
 * Valida la petición para disparar la planificación automática del día. La autorización (puerta de la
 * app, rol planificador) la resuelve el middleware; acá solo se valida la forma de la entrada.
 */
final class GenerateDailyPlanRequest extends FormRequest
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
            'plan_date' => ['required', 'string', 'date_format:Y-m-d'],
            'strategy' => ['required', 'string', 'in:optimization,slots'],
            // Overrides puntuales de los parámetros configurables (todos opcionales).
            'params' => ['sometimes', 'array'],
            'params.depart_at' => ['sometimes', 'string', 'date'],
            'params.capacity_slack' => ['sometimes', 'numeric', 'min:0', 'lt:1'],
            'params.default_weight_kg_per_package' => ['sometimes', 'numeric', 'gt:0'],
            'params.default_volume_m3_per_package' => ['sometimes', 'numeric', 'gt:0'],
            'params.origin_node_id' => ['sometimes', 'string', 'uuid'],
        ];
    }

    public function toCommand(): GenerateDailyPlan
    {
        /** @var array{plan_date: string, strategy: string, params?: array<string, mixed>} $data */
        $data = $this->validated();

        return new GenerateDailyPlan(
            planDate: $data['plan_date'],
            strategy: $data['strategy'],
            params: $data['params'] ?? [],
        );
    }
}
