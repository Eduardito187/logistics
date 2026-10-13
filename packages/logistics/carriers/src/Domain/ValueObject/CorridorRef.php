<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Carriers\Domain\Exception\CarriersException;

/**
 * Referencia a un corredor: par origen→destino (como texto) y tipo de corredor.
 *
 * Usa el mismo vocabulario de tipos que network (CD→CD, CD→tienda, etc.) pero por string, a propósito:
 * este contexto NO depende del package network. El origen y el destino se guardan como texto (id o
 * código de nodo) para no acoplarse a la identidad concreta de otro contexto.
 */
final readonly class CorridorRef
{
    /**
     * Tipos de corredor válidos. Deben coincidir con los valores de Logistics\Network CorridorType,
     * pero se replican como texto para no importar network.
     */
    private const TYPES = [
        'cd_to_customer',
        'cd_to_cd',
        'cd_to_store',
        'store_to_store',
        'store_to_cd',
        'store_pickup',
    ];

    private function __construct(
        public string $from,
        public string $to,
        public string $type,
    ) {}

    public static function of(string $from, string $to, string $type): self
    {
        $from = trim($from);
        $to = trim($to);
        $type = trim($type);

        if ($from === '') {
            throw CarriersException::because('El origen del corredor no puede estar vacío.');
        }
        if ($to === '') {
            throw CarriersException::because('El destino del corredor no puede estar vacío.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw CarriersException::because("Tipo de corredor inválido: {$type}.");
        }

        return new self($from, $to, $type);
    }

    public function equals(self $other): bool
    {
        return $this->from === $other->from
            && $this->to === $other->to
            && $this->type === $other->type;
    }

    /**
     * @return array{from: string, to: string, type: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'type' => $this->type];
    }
}
