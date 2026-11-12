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
     * @param array<int, list<non-empty-string>> $keys id de clave => lista de claves públicas en base64
     *                                                 (varias por id en la red de seguridad append-only)
     * @param bool                               $open true = modo ABIERTO: la app NO debe verificar la firma
     *                                                 de NINGÚN QR (ni k:0 ni las k:1 ya impresas); valida por
     *                                                 pertenencia (orden). Así una etiqueta impresa nunca se rechaza.
     */
    public function __construct(
        public array $keys,
        public bool $open = false,
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
     * Cada pública viaja como una entrada {id, public_key}; un mismo id puede aparecer en varias entradas
     * (red de seguridad append-only). La app ya elige por el "k" del QR y prueba las que tengan ese id.
     *
     * @return array{open: bool, active_key_id: int|null, keys: list<array{id: int, public_key: string}>}
     */
    public function toArray(): array
    {
        $keys = [];
        foreach ($this->keys as $id => $publicKeys) {
            foreach ($publicKeys as $publicKey) {
                $keys[] = ['id' => $id, 'public_key' => $publicKey];
            }
        }

        return [
            // Señal para la app: en modo abierto NO verifica firma de ningún QR y valida por pertenencia.
            'open' => $this->open,
            'active_key_id' => $this->activeKeyId(),
            'keys' => $keys,
        ];
    }
}
