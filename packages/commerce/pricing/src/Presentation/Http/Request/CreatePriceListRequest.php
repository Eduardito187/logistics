<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Request;

use Commerce\Pricing\Application\Command\CreatePriceList\CreatePriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del cuerpo para crear una lista de precios (puerta `integrations`, gestión PIM).
 *
 * La autenticación del integrador la resuelve el middleware de la puerta; aquí solo se valida la forma
 * del cuerpo y se arma el comando de Application. La moneda por defecto es BOB (Dismac opera en Bolivia).
 */
final class CreatePriceListRequest extends FormRequest
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
        $channels = array_map(
            static fn (Channel $channel): string => $channel->value,
            Channel::cases(),
        );

        return [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'string', Rule::in($channels)],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): CreatePriceList
    {
        /** @var array{name: string, channel: string, currency?: string|null} $data */
        $data = $this->validated();

        return new CreatePriceList(
            name: $data['name'],
            channel: $data['channel'],
            currency: isset($data['currency']) && $data['currency'] !== ''
                ? (string) $data['currency']
                : Money::DEFAULT_CURRENCY,
        );
    }
}
