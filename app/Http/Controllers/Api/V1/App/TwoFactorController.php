<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Auth\TwoFactor\RecoveryCodes;
use App\Auth\TwoFactor\TotpService;
use App\Http\Controllers\Api\V1\App\Concerns\IssuesDeviceTokens;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Segundo factor (TOTP) de la app Flutter (`/api/v1/app/auth/2fa/*`).
 *
 * El secreto TOTP es POR USUARIO y COMPARTIDO con el admin Filament (misma columna
 * `app_authentication_secret` vía {@see User::getAppAuthenticationSecret()}), de modo
 * que el 2FA quede unificado entre la app móvil y el panel.
 *
 * Flujo: enroll (genera secreto + recovery codes, sin confirmar) → confirm (activa) →
 * en adelante el login exige verify. disable limpia el segundo factor.
 */
final class TwoFactorController
{
    use IssuesDeviceTokens;

    /**
     * Ventana de tolerancia (en pasos de 30 s) hacia atrás/adelante al verificar el TOTP.
     */
    private const VERIFY_WINDOW = 1;

    public function __construct(private readonly TotpService $totp) {}

    /**
     * Enrola el segundo factor: genera un secreto nuevo (aún NO confirmado) y recovery
     * codes, los guarda y los devuelve una única vez para que la app muestre el QR
     * (a partir de `otpauth_uri`) o la clave en texto.
     *
     * Si el usuario ya tenía 2FA, re-enrolar reemplaza el secreto y lo deja sin confirmar
     * hasta que confirm() lo reactive; el 2FA previo deja de exigirse recién al confirmar.
     */
    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->totp->generateSecret();
        $recoveryCodes = RecoveryCodes::generate();

        $user->saveAppAuthenticationSecret($secret);
        $user->saveAppAuthenticationRecoveryCodes($recoveryCodes);
        // Un secreto nuevo arranca sin confirmar: no exige 2FA hasta confirm().
        $user->forceFill(['two_factor_confirmed_at' => null])->save();

        return new JsonResponse([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri(
                $secret,
                $user->getAppAuthenticationHolderName(),
                $this->issuer(),
            ),
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    /**
     * Confirma el enrolamiento: verifica un código TOTP contra el secreto recién generado
     * y, si es válido, marca el segundo factor como ACTIVO (`two_factor_confirmed_at`).
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $secret = $user->getAppAuthenticationSecret();

        if ($secret === null || $secret === '') {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication has not been set up yet.'],
            ]);
        }

        if (!$this->totp->verify($secret, $data['code'], self::VERIFY_WINDOW)) {
            throw ValidationException::withMessages([
                'code' => ['The provided code is invalid.'],
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return new JsonResponse(['message' => 'Two-factor authentication enabled.']);
    }

    /**
     * Completa el reto de login: recibe el `challenge_id` emitido por el login y un código
     * (TOTP o recovery code). Si es válido, liga el dispositivo y emite el token Sanctum
     * definitivo (misma respuesta que un login directo).
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            // Aceptado por conveniencia del cliente; la verdad la lleva el challenge.
            'email' => ['sometimes', 'nullable', 'email'],
        ]);

        $cacheKey = AuthController::CHALLENGE_CACHE_PREFIX.hash('sha256', $data['challenge_id']);
        $challenge = Cache::get($cacheKey);

        if (!is_array($challenge) || !isset($challenge['user_id'], $challenge['device'])) {
            throw ValidationException::withMessages([
                'challenge_id' => ['The verification challenge is invalid or has expired.'],
            ]);
        }

        $user = User::query()->find($challenge['user_id']);

        if (!$user instanceof User || !$this->userPassesSecondFactor($user, $data['code'])) {
            // No se consume el reto: la app puede reintentar hasta que expire (el throttle frena la fuerza bruta).
            throw ValidationException::withMessages([
                'code' => ['The provided code is invalid.'],
            ]);
        }

        // Un solo uso: consumir el reto apenas se valida.
        Cache::forget($cacheKey);

        /** @var array{fingerprint: string, platform?: string|null, name?: string|null, push_token?: string|null} $deviceInput */
        $deviceInput = $challenge['device'];
        $device = $this->bindDevice($user, $deviceInput);

        return $this->authSuccessResponse($user, $device);
    }

    /**
     * Deshabilita el segundo factor tras reverificar la identidad del usuario con un
     * código TOTP vigente o su contraseña. Limpia secreto, recovery codes y confirmación.
     */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required_without:password', 'nullable', 'string'],
            'password' => ['required_without:code', 'nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!$this->identityReconfirmed($user, $data['code'] ?? null, $data['password'] ?? null)) {
            throw ValidationException::withMessages([
                'code' => ['Identity could not be verified.'],
            ]);
        }

        $user->saveAppAuthenticationSecret(null);
        $user->saveAppAuthenticationRecoveryCodes(null);
        $user->forceFill(['two_factor_confirmed_at' => null])->save();

        return new JsonResponse(['message' => 'Two-factor authentication disabled.']);
    }

    /**
     * Valida el código del reto de login: primero como TOTP y, si falla, como recovery
     * code (consumiéndolo). Devuelve true si cualquiera de los dos es válido.
     */
    private function userPassesSecondFactor(User $user, string $code): bool
    {
        $secret = $user->getAppAuthenticationSecret();

        if ($secret !== null && $secret !== '' && $this->totp->verify($secret, $code, self::VERIFY_WINDOW)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Reconfirma identidad para operaciones sensibles (disable): acepta un TOTP vigente,
     * un recovery code o la contraseña de la cuenta.
     */
    private function identityReconfirmed(User $user, ?string $code, ?string $password): bool
    {
        if ($password !== null && $password !== '' && Hash::check($password, $user->password)) {
            return true;
        }

        if ($code !== null && $code !== '') {
            return $this->userPassesSecondFactor($user, $code);
        }

        return false;
    }

    /**
     * Comprueba el código contra los recovery codes guardados en tiempo constante y, si
     * coincide, lo consume (lo elimina de la lista) para que sea de un solo uso.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $user->getAppAuthenticationRecoveryCodes();

        if (!is_array($stored) || $stored === []) {
            return false;
        }

        $matched = false;
        $remaining = [];

        foreach ($stored as $recoveryCode) {
            if (is_string($recoveryCode) && !$matched && hash_equals($recoveryCode, $code)) {
                $matched = true;

                continue; // consumido: no se conserva.
            }

            $remaining[] = $recoveryCode;
        }

        if ($matched) {
            $user->saveAppAuthenticationRecoveryCodes($remaining);
        }

        return $matched;
    }

    /**
     * Emisor que viaja en el `otpauth_uri` (lo que la app de autenticación muestra como
     * nombre de la cuenta/servicio).
     */
    private function issuer(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'Dismac Logistics';
    }
}
