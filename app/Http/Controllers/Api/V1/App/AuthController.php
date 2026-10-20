<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación de la app interna Flutter (`/api/v1/app/auth/*`).
 *
 * Login por usuario + dispositivo, logout, rotación de token y perfil. Cada token se
 * ata al dispositivo (ver {@see Device}) y expira según `config('sanctum.expiration')`.
 */
final class AuthController
{
    /**
     * Inicia sesión: valida credenciales, liga el dispositivo al usuario y emite un
     * token nuevo (revocando los tokens previos de ese mismo dispositivo).
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

        $device = $this->bindDevice($user, $data['device']);

        // Rotación: un dispositivo tiene un solo token vigente a la vez.
        $device->tokens()->delete();

        $token = $this->issueToken($user, $device);

        return new JsonResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiresAt()?->toIso8601String(),
            'user' => $this->profile($user),
            'device' => $this->devicePayload($device),
        ]);
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
     * Crea o actualiza el dispositivo y lo liga al usuario (binding usuario↔dispositivo).
     *
     * @param array{fingerprint: string, platform?: string|null, name?: string|null, push_token?: string|null} $input
     */
    private function bindDevice(User $user, array $input): Device
    {
        $device = Device::query()->firstOrNew(['fingerprint' => $input['fingerprint']]);

        $device->fill([
            'user_id' => $user->getKey(),
            'platform' => $input['platform'] ?? $device->platform,
            'name' => $input['name'] ?? $device->name,
            'push_token' => $input['push_token'] ?? $device->push_token,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);
        $device->save();

        return $device;
    }

    /**
     * Emite un token con las habilidades del usuario, ligado al dispositivo y con
     * expiración.
     */
    private function issueToken(User $user, Device $device): string
    {
        $newToken = $user->createToken(
            'app:'.$device->uuid,
            $user->abilities(),
            $this->expiresAt(),
        );

        /** @var PersonalAccessToken $accessToken */
        $accessToken = $newToken->accessToken;
        $accessToken->forceFill(['device_id' => $device->id])->save();

        return $newToken->plainTextToken;
    }

    private function expiresAt(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return is_int($minutes) ? now()->addMinutes($minutes) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => array_map(static fn ($role): string => $role->value, $user->roles()),
            // Nodo de tienda al que pertenece la cuenta (o null para roles sin tienda).
            'node' => $user->assignedNode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function devicePayload(Device $device): array
    {
        return [
            'uuid' => $device->uuid,
            'platform' => $device->platform,
            'name' => $device->name,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }
}
