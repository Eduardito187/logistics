<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Referencia personal de un chofer o ayudante: alguien a quien contactar (familiar, conocido, etc.).
 *
 * Solo el nombre es obligatorio; parentesco, teléfono y nota son opcionales. Se persiste como un objeto
 * dentro de la lista jsonb `references`.
 */
final readonly class PersonalReference
{
    private function __construct(
        public string $name,
        public ?string $relationship,
        public ?string $phone,
        public ?string $note,
    ) {}

    public static function of(
        string $name,
        ?string $relationship = null,
        ?string $phone = null,
        ?string $note = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw FleetException::because('El nombre de la referencia no puede estar vacío.');
        }

        return new self(
            $name,
            self::clean($relationship),
            self::clean($phone),
            self::clean($note),
        );
    }

    /**
     * @return array{name: string, relationship: string|null, phone: string|null, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'note' => $this->note,
        ];
    }

    /**
     * Recorta y convierte en null los textos vacíos de los campos opcionales.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
