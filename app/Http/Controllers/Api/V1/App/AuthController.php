<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Api\V1\App\Concerns\IssuesDeviceTokens;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de la app interna Flutter (`/api/v1/app/auth/*`).
 *
 * Login por usuario + dispositivo, logout, rotación de token y perfil. Cada token se
 * ata al dispositivo (ver {@see Device}) y expira según `config('sanctum.expiration')`.
 *
 * Si el usuario tiene el segundo factor (TOTP) confirmado, el login NO emite el token
 * directamente: devuelve un reto efímero (`challenge_id`) que la app completa en
 * {@see TwoFactorController::verify()}. Los usuarios SIN 2FA conservan el contrato de
 * login original (token inmediato).
 */
final class AuthController
{
    use IssuesDeviceTokens;

    /**
     * Prefijo de la clave de caché donde vive el reto de 2FA entre login y verify.
     */
    public const CHALLENGE_CACHE_PREFIX = 'app:2fa:challenge:';

    /**
     * Vida del reto de 2FA (minutos). El usuario debe introducir el código antes de que expire.
     */
    public const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Inicia sesión: valida credenciales y, según el estado de 2FA del usuario, emite el
     * token directo (sin 2FA) o devuelve un reto de segundo factor (con 2FA confirmado).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device.fingerprint' => ['required', 'string', 'max:255'],
            'device.platform' => ['nullable', 'string', 'max:64'],
            'device.name' => ['nullable', 'string', 'max:255'],
            'device.push_token' => ['nullable', 'string', 'max:512'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || !Hash::check($data['password'], $user->password)) {
            // Mensaje genérico: no revela si el correo existe.
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Si el usuario tiene el segundo factor confirmado, se interpone el reto de 2FA:
        // no se emite el token hasta que verify() confirme el código.
        if ($this->twoFactorIsEnabled($user)) {
            return $this->issueChallenge($user, $data['device']);
        }

        // Camino sin 2FA: contrato original intacto (token inmediato).
        $device = $this->bindDevice($user, $data['device']);

        return $this->authSuccessResponse($user, $device);
    }

    /**
     * Cierra sesión revocando únicamente el token en uso.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return new JsonResponse(['message' => 'Signed out.']);
    }

    /**
     * Rota el token: emite uno nuevo para el mismo dispositivo y revoca el anterior.
     */
    public function refresh(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();

        if (!$current instanceof PersonalAccessToken || $current->device_id === null) {
            throw ValidationException::withMessages([
                'token' => ['The current token cannot be refreshed.'],
            ]);
        }

        $device = Device::findOrFail($current->device_id);
        $device->tokens()->delete();

        $token = $this->issueToken($user, $device);

        return new JsonResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiresAt()?->toIso8601String(),
        ]);
    }

    /**
     * Devuelve el perfil del usuario autenticado, sus roles y el dispositivo en uso.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        // EnsureDeviceToken ya dejó el dispositivo cargado en la relación del token (sin re-consultar).
        $device = null;
        if ($token instanceof PersonalAccessToken && $token->device_id !== null) {
            $loaded = $token->relationLoaded('device') ? $token->getRelation('device') : null;
            $device = $loaded instanceof Device ? $loaded : Device::find($token->device_id);
        }

        return new JsonResponse([
            'user' => $this->profile($user),
            'device' => $device !== null ? $this->devicePayload($device) : null,
            'abilities' => $user->abilities(),
        ]);
    }

    /**
     * Indica si el usuario tiene el segundo factor ACTIVO (secreto guardado y confirmado).
     */
    private function twoFactorIsEnabled(User $user): bool
    {
        $secret = $user->getAppAuthenticationSecret();

        return $secret !== null && $secret !== '' && $user->getAttribute('two_factor_confirmed_at') !== null;
    }

    /**
     * Crea un reto efímero de 2FA ligado al usuario + datos del dispositivo y lo guarda
     * en caché con vida corta. El `challenge_id` es un token opaco de un solo uso que la
     * app presenta en verify(); nunca se derivan de él las credenciales.
     *
     * @param array{fingerprint: string, platform?: string|null, name?: string|null, push_token?: string|null} $device
     */
    private function issueChallenge(User $user, array $device): JsonResponse
    {
        $challengeId = Str::random(64);

        Cache::put(
            self::CHALLENGE_CACHE_PREFIX.hash('sha256', $challengeId),
            [
                'user_id' => $user->getKey(),
                'device' => $device,
            ],
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
        );

        return new JsonResponse([
            'two_factor_required' => true,
            'challenge_id' => $challengeId,
            'expires_in' => self::CHALLENGE_TTL_MINUTES * 60,
        ]);
    }
}
