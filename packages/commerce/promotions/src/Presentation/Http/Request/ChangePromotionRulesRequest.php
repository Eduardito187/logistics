<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Request;

use Commerce\Promotions\Application\Command\ChangePromotionRules\ChangePromotionRules;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del cuerpo para cambiar las reglas de una promoción (puerta `integrations`, gestión).
 *
 * El id de la promoción viene por la ruta; el cuerpo trae las nuevas reglas (tipo, valor y condiciones).
 * `value` se interpreta según el tipo: basis points (0..10000) para porcentaje, centavos para monto fijo,
 * 0 para envío gratis. El cambio incrementa la versión del agregado.
 */
final class ChangePromotionRulesRequest extends FormRequest
{
    /** Canales válidos del dominio de promociones (coinciden con PromotionConditions). */
    private const CHANNELS = ['Retail', 'B2B', 'C2C'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $types = array_map(
            static fn (PromotionType $type): string => $type->value,
            PromotionType::cases(),
        );

        return [
            'type' => ['required', 'string', Rule::in($types)],
            'value' => ['required', 'integer', 'min:0'],
            'min_subtotal_cents' => ['nullable', 'integer', 'min:0'],
            'channel' => ['nullable', 'string', Rule::in(self::CHANNELS)],
            'starts_at' => ['nullable', 'string', 'date'],
            'ends_at' => ['nullable', 'string', 'date'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado y el id de la ruta.
     */
    public function toCommand(string $promotionId): ChangePromotionRules
    {
        /** @var array{type: string, value: int, min_subtotal_cents?: int|null, channel?: string|null, starts_at?: string|null, ends_at?: string|null} $data */
        $data = $this->validated();

        return new ChangePromotionRules(
            promotionId: $promotionId,
            type: $data['type'],
            value: $data['value'],
            minSubtotalCents: $data['min_subtotal_cents'] ?? 0,
            channel: $data['channel'] ?? null,
            startsAt: $data['starts_at'] ?? null,
            endsAt: $data['ends_at'] ?? null,
        );
    }
}
