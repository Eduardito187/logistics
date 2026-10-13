<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Security;

use Logistics\Access\Domain\Contract\TokenHasher;

/**
 * Implementación del puerto {@see TokenHasher} con SHA-256 y comparación en tiempo constante.
 *
 * Los secretos de token son de alta entropía (48 caracteres aleatorios), así que no necesitan el costo
 * de bcrypt: un digest rápido permite verificar credenciales de servicio a escala sin volverse un
 * cuello de botella. La comparación usa `hash_equals` para evitar ataques de temporización.
 */
final class Sha256TokenHasher implements TokenHasher
{
    public function hash(string $plainSecret): string
    {
        return hash('sha256', $plainSecret);
    }

    public function verify(string $plainSecret, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return hash_equals($hash, $this->hash($plainSecret));
    }
}
