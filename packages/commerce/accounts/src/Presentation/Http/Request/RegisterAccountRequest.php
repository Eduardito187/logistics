<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Request;

use Commerce\Accounts\Application\Command\RegisterAccount\RegisterAccount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para registrar una cuenta comercial (puerta `channels`).
 *
 * El usuario de `access` (al que se asocia la cuenta) viaja por UUID; la autenticación del canal la
 * resuelve el middleware de la puerta. Aquí solo se valida la forma del cuerpo y se arma el comando.
 */
final class RegisterAccountRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:40'],
            'user_id' => ['required', 'uuid'],
            'display_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): RegisterAccount
    {
        /** @var array{type: string, user_id: string, display_name: string, tax_id?: string|null} $data */
        $data = $this->validated();

        return new RegisterAccount(
            type: $data['type'],
            userId: $data['user_id'],
            displayName: $data['display_name'],
            taxId: isset($data['tax_id']) && $data['tax_id'] !== ''
                ? (string) $data['tax_id']
                : null,
        );
    }
}
