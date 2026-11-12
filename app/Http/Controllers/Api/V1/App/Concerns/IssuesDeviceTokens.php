<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App\Concerns;

use App\Http\Controllers\Api\V1\App\AuthController;
use App\Http\Controllers\Api\V1\App\TwoFactorController;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Lógica compartida de emisión de tokens ligados a dispositivo para la puerta `/app/*`.
 *
 * La usan {@see AuthController} (login/refresh/me) y
 * {@see TwoFactorController} (verify del segundo factor),
 * para que el binding usuario↔dispositivo y la forma de la respuesta de sesión sean
 * idénticos tanto si el login fue directo como si pasó por el reto de 2FA.
 */
trait IssuesDeviceTokens
{
    /**
     * Crea o actualiza el dispositivo, lo liga al usuario (binding usuario↔dispositivo) y aplica la regla
     * de sesión 1-por-plataforma: desaloja cualquier OTRO dispositivo activo del usuario en el MISMO
     * bucket (web|app), sin tocar el otro bucket (web y app coexisten). El mismo `fingerprint` es un
     * reemplazo natural (misma fila), así que no se desaloja a sí mismo.
     *
     * @param array{fingerprint: string, platform?: string|null, name?: string|null, push_token?: string|null, client_kind?: string|null} $input
     */
    protected function bindDevice(User $user, array $input): Device
    {
        $bucket = Device::resolveBucket($input['platform'] ?? null, $input['client_kind'] ?? null);

        $device = Device::query()->firstOrNew(['fingerprint' => $input['fingerprint']]);

        // El nombre que el dueño le puso al equipo (PATCH /devices/{uuid}) sobrevive al re-login: la app
        // manda siempre el genérico "App interna", que no lo pisa si el equipo sigue siendo del mismo
        // usuario. Si cambia de usuario (teléfono compartido), el nombre del anterior no se hereda.
        $incomingName = $input['name'] ?? null;
        $sameOwner = $device->exists && $device->user_id !== null && (int) $device->user_id === (int) $user->getKey();
        $keepName = $sameOwner && $device->name !== null && ($incomingName === null || strcasecmp(trim($incomingName), Device::GENERIC_NAME) === 0);

        $device->fill([
            'user_id' => $user->getKey(),
            'platform' => $input['platform'] ?? $device->platform,
            'session_bucket' => $bucket,
            'name' => $keepName ? $device->name : $incomingName,
            'push_token' => $input['push_token'] ?? $device->push_token,
            'last_seen_at' => now(),
            'revoked_at' => null,
            'revoked_reason' => null,
        ]);
        $device->save();

        $this->supersedeSameBucketSessions($user, $device, $bucket);

        return $device;
    }

    /**
     * Desaloja las demás sesiones activas del usuario en el mismo bucket (≤1 activa por bucket).
     */
    protected function supersedeSameBucketSessions(User $user, Device $current, string $bucket): void
    {
        $others = Device::query()
            ->where('user_id', $user->getKey())
            ->where('session_bucket', $bucket)
            ->whereKeyNot($current->getKey())
            ->whereNull('revoked_at')
            ->get();

        foreach ($others as $other) {
            $other->supersede();
        }
    }

    /**
     * Emite un token con las habilidades del usuario, ligado al dispositivo y con expiración.
     */
    protected function issueToken(User $user, Device $device): string
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

    protected function expiresAt(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return is_int($minutes) ? now()->addMinutes($minutes) : null;
    }

    /**
     * Rota los tokens del dispositivo (uno solo vigente a la vez), emite uno nuevo y
     * devuelve la respuesta de sesión completa (idéntica para login directo y post-2FA).
     */
    protected function authSuccessResponse(User $user, Device $device): JsonResponse
    {
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
     * @return array<string, mixed>
     */
    protected function profile(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => array_map(static fn ($role): string => $role->value, $user->roles()),
            // Nodo de tienda al que pertenece la cuenta (o null para roles sin tienda).
            'node' => $user->assignedNode(),
            // Transportista al que pertenece la cuenta (identidad server-side del rol transportista;
            // null para el resto de roles). La app lo lee de aquí y deja de elegir "quién soy".
            'carrier' => $user->assignedCarrier(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function devicePayload(Device $device): array
    {
        return [
            'uuid' => $device->uuid,
            'platform' => $device->platform,
            'name' => $device->name,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }
}
