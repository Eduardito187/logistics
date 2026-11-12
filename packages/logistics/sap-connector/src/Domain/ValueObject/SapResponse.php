<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

/**
 * Respuesta cruda de SAP a un aviso: código HTTP y cuerpo tal cual (la forma es de SAP). Se guarda
 * para diagnóstico; nuestra plataforma no la interpreta ni depende de ella.
 */
final readonly class SapResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}

    /**
     * ¿SAP aceptó el aviso? Cualquier 2xx cuenta como entregado.
     */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
