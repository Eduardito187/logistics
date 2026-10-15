<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

/**
 * Puerto de hashing de contraseñas. El dominio nunca conoce el algoritmo (bcrypt/argon): solo pide
 * hashear y verificar. La implementación vive en Infrastructure (usa el Hash de Laravel).
 *
 * Mantener esto como puerto conserva el dominio en PHP puro y permite testearlo con un doble.
 */
interface PasswordHasher
{
    public function hash(string $plain): string;

    public function verify(string $plain, string $hash): bool;
}
