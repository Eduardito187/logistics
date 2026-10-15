<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Access\Domain\Exception\AccessException;

/**
 * Secreto en claro de un token de integración recién emitido, junto con su prefijo público.
 *
 * El token completo que recibe el integrador es "<prefix>.<secret>": el prefijo (p. ej. "itk_ab12cd34")
 * es público, se indexa y permite ubicar la fila sin revelar el secreto; el secreto solo se guarda
 * hasheado. Este objeto transporta el valor en claro UNA sola vez (al crear o rotar) para mostrarlo.
 */
final readonly class PlainTokenSecret
{
    private function __construct(
        public string $prefix,
        public string $secret,
    ) {}

    public static function of(string $prefix, string $secret): self
    {
        $prefix = trim($prefix);
        $secret = trim($secret);

        if ($prefix === '' || $secret === '') {
            throw AccessException::because('El prefijo y el secreto del token no pueden estar vacíos.');
        }

        return new self($prefix, $secret);
    }

    /**
     * Token completo tal como lo usa el cliente: "<prefix>.<secret>".
     */
    public function full(): string
    {
        return $this->prefix.'.'.$this->secret;
    }
}
