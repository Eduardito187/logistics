<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del guardado de la suscripción Web Push (K-04) desde la página pública.
 *
 * Valida FORMA de la suscripción estándar del navegador (endpoint https + claves p256dh/auth); el
 * dominio (WebPushSubscription) revalida y es la fuente de verdad. La única credencial es el token de la
 * URL, por eso authorize() devuelve true.
 */
final class RegisterPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El cuerpo llega con la forma estándar de PushSubscription.toJSON(): { endpoint, keys: { p256dh, auth } }.
     * Se normaliza a claves planas para validarlo.
     */
    protected function prepareForValidation(): void
    {
        $keys = $this->input('keys');
        if (is_array($keys)) {
            $this->merge([
                'p256dh' => $keys['p256dh'] ?? null,
                'auth' => $keys['auth'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:1024', 'url', 'starts_with:https://'],
            'p256dh' => ['required', 'string', 'max:255'],
            'auth' => ['required', 'string', 'max:255'],
        ];
    }

    public function endpoint(): string
    {
        return (string) $this->input('endpoint');
    }

    public function p256dh(): string
    {
        return (string) $this->input('p256dh');
    }

    public function auth(): string
    {
        return (string) $this->input('auth');
    }
}
