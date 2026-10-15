<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Modelo de lectura del bundle de claves públicas: forma plana y primitiva, lista para la API/app.
 *
 * Cada clave viaja como `id` (el campo "k" del QR) y su clave pública Ed25519 en base64. La app
 * elige la clave por el `k` del payload y verifica la firma offline. Si se cachea, se cachea su
 * toArray() (regla 7 del proyecto), nunca un objeto de dominio.
 */
final readonly class PublicKeyBundleView
{
    /**
     * @param array<int, non-empty-string> $keys id de clave => clave pública en base64
     */
    public function __construct(
        public array $keys,
    ) {}

    /** Id de la clave activa (la mayor por convención de rotación), o null si no hay ninguna. */
    public function activeKeyId(): ?int
    {
        if ($this->keys === []) {
            return null;
        }

        return max(array_keys($this->keys));
    }

    /**
     * @return array{active_key_id: int|null, keys: list<array{id: int, public_key: string}>}
     */
    public function toArray(): array
    {
        $keys = [];
        foreach ($this->keys as $id => $publicKey) {
            $keys[] = ['id' => $id, 'public_key' => $publicKey];
        }

        return [
            'active_key_id' => $this->activeKeyId(),
            'keys' => $keys,
        ];
    }
}
