<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Request;

use Commerce\Promotions\Application\Query\EvaluatePromotions\EvaluatePromotions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del cuerpo de evaluación de promociones (puerta `channels`, storefront).
 *
 * El storefront envía el contexto del carrito (subtotal en centavos, canal y, opcional, un código de
 * cupón) y recibe las promociones aplicables con sus descuentos. Es una evaluación de lectura, sin
 * efecto. Los canales válidos son los del dominio (Retail/B2B/C2C). Moneda implícita: BOB (centavos).
 */
final class EvaluatePromotionsRequest extends FormRequest
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
        return [
            'subtotal_cents' => ['required', 'integer', 'min:0'],
            'channel' => ['required', 'string', Rule::in(self::CHANNELS)],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * Arma la query de Application a partir del payload ya validado.
     */
    public function toQuery(): EvaluatePromotions
    {
        /** @var array{subtotal_cents: int, channel: string, coupon_code?: string|null} $data */
        $data = $this->validated();

        $coupon = isset($data['coupon_code']) && trim((string) $data['coupon_code']) !== ''
            ? (string) $data['coupon_code']
            : null;

        return new EvaluatePromotions(
            subtotalCents: $data['subtotal_cents'],
            channel: $data['channel'],
            couponCode: $coupon,
        );
    }
}
