<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Request;

use Commerce\Pricing\Application\Command\SetPrice\SetPrice;
use Commerce\Pricing\Domain\ValueObject\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para fijar el precio de un SKU en una lista (puerta `integrations`, gestión PIM).
 *
 * El id de la lista llega por la ruta (no por el cuerpo). El monto es el precio al público —IVA incluido—
 * en centavos (entero), nunca float. La moneda por defecto es BOB. La autenticación del integrador la
 * resuelve el middleware de la puerta; aquí solo se valida la forma del cuerpo.
 */
final class SetPriceRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:255'],
            'gross_amount_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload validado y el id de lista tomado de la ruta.
     */
    public function toCommand(string $priceListId): SetPrice
    {
        /** @var array{sku: string, gross_amount_cents: int|string, currency?: string|null} $data */
        $data = $this->validated();

        return new SetPrice(
            priceListId: $priceListId,
            sku: $data['sku'],
            grossAmountCents: (int) $data['gross_amount_cents'],
            currency: isset($data['currency']) && $data['currency'] !== ''
                ? (string) $data['currency']
                : Money::DEFAULT_CURRENCY,
        );
    }
}
