<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Security;

use Illuminate\Contracts\Hashing\Hasher;
use Logistics\Access\Domain\Contract\PasswordHasher;

/**
 * Implementación del puerto {@see PasswordHasher} sobre el hasher configurado de Laravel (bcrypt/argon).
 *
 * Es detalle de infraestructura: el dominio solo conoce el puerto. Se inyecta el contrato `Hasher` del
 * framework para no acoplar a un algoritmo concreto y poder rehashear al cambiar el costo.
 */
final readonly class BcryptPasswordHasher implements PasswordHasher
{
    public function __construct(
        private Hasher $hasher,
    ) {}

    public function hash(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return $this->hasher->check($plain, $hash);
    }
}
