<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Request;

use Commerce\Promotions\Application\Command\CreatePromotion\CreatePromotion;
use Commerce\Promotions\Domain\ValueObject\PromotionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del cuerpo para crear una promoción (puerta `integrations`, gestión).
 *
 * La autenticación del integrador la resuelve el middleware de la puerta; aquí solo se valida la forma
 * del cuerpo y se arma el comando de Application. La moneda por defecto es BOB (Dismac opera en Bolivia).
 * `value` se interpreta según el tipo: basis points (0..10000) para porcentaje, centavos para monto fijo,
 * 0 para envío gratis. `code` null = promoción automática; no-null = cupón.
 */
final class CreatePromotionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in($types)],
            'value' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'code' => ['nullable', 'string', 'max:64'],
            'min_subtotal_cents' => ['nullable', 'integer', 'min:0'],
            'channel' => ['nullable', 'string', Rule::in(self::CHANNELS)],
            'starts_at' => ['nullable', 'string', 'date'],
            'ends_at' => ['nullable', 'string', 'date'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): CreatePromotion
    {
        /** @var array{name: string, type: string, value: int, currency?: string|null, code?: string|null, min_subtotal_cents?: int|null, channel?: string|null, starts_at?: string|null, ends_at?: string|null} $data */
        $data = $this->validated();

        return new CreatePromotion(
            name: $data['name'],
            type: $data['type'],
            value: $data['value'],
            currency: isset($data['currency']) && $data['currency'] !== ''
                ? (string) $data['currency']
                : 'BOB',
            code: isset($data['code']) && trim((string) $data['code']) !== ''
                ? (string) $data['code']
                : null,
            minSubtotalCents: $data['min_subtotal_cents'] ?? 0,
            channel: $data['channel'] ?? null,
            startsAt: $data['starts_at'] ?? null,
            endsAt: $data['ends_at'] ?? null,
        );
    }
}
