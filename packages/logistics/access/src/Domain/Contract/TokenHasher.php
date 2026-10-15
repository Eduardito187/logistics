<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

/**
 * Puerto de hashing de secretos de tokens de integración. Solo se persiste el hash del secreto; el
 * valor en claro se muestra una única vez al crearlo o rotarlo y no se puede recuperar después.
 *
 * Se separa de {@see PasswordHasher} porque un secreto de token es de alta entropía: su verificación
 * puede usar un hash rápido (p. ej. SHA-256) sin el costo de bcrypt, y así soportar validación a escala.
 */
interface TokenHasher
{
    public function hash(string $plainSecret): string;

    public function verify(string $plainSecret, string $hash): bool;
}
