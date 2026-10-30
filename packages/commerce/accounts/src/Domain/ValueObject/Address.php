<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\ValueObject;

use Commerce\Accounts\Domain\Exception\AccountsException;

/**
 * Dirección de compra/envío de una cuenta (p. ej. "Casa", "Oficina").
 *
 * Es un value object que vive DENTRO del agregado Account (no tiene identidad propia ni se consulta de
 * forma independiente en esta fase): por eso se persiste como JSON en la fila de la cuenta, no en tabla
 * aparte. La etiqueta (`label`) la identifica dentro de la cuenta. `isDefault` marca la dirección por
 * defecto; el agregado garantiza que a lo sumo una la tenga.
 */
final readonly class Address
{
    private function __construct(
        public string $label,
        public string $recipient,
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $state,
        public ?string $phone,
        public bool $isDefault,
    ) {}

    public static function of(
        string $label,
        string $recipient,
        string $line1,
        ?string $line2,
        string $city,
        ?string $state,
        ?string $phone,
        bool $isDefault = false,
    ): self {
        $label = trim($label);
        if ($label === '') {
            throw AccountsException::because('La etiqueta de la dirección no puede estar vacía.');
        }
        $recipient = trim($recipient);
        if ($recipient === '') {
            throw AccountsException::because('El destinatario de la dirección no puede estar vacío.');
        }
        $line1 = trim($line1);
        if ($line1 === '') {
            throw AccountsException::because('La dirección (línea 1) no puede estar vacía.');
        }
        $city = trim($city);
        if ($city === '') {
            throw AccountsException::because('La ciudad de la dirección no puede estar vacía.');
        }

        return new self(
            $label,
            $recipient,
            $line1,
            self::nullIfBlank($line2),
            $city,
            self::nullIfBlank($state),
            self::nullIfBlank($phone),
            $isDefault,
        );
    }

    /**
     * Reconstituye una dirección desde su forma primitiva (columna JSON).
     *
     * @param array{label: string, recipient: string, line1: string, line2?: ?string, city: string, state?: ?string, phone?: ?string, is_default?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['label'],
            $data['recipient'],
            $data['line1'],
            $data['line2'] ?? null,
            $data['city'],
            $data['state'] ?? null,
            $data['phone'] ?? null,
            $data['is_default'] ?? false,
        );
    }

    /**
     * Devuelve una copia de esta dirección marcada como NO predeterminada.
     */
    public function asNonDefault(): self
    {
        return new self(
            $this->label,
            $this->recipient,
            $this->line1,
            $this->line2,
            $this->city,
            $this->state,
            $this->phone,
            false,
        );
    }

    public function hasLabel(string $label): bool
    {
        return $this->label === trim($label);
    }

    /**
     * @return array{label: string, recipient: string, line1: string, line2: ?string, city: string, state: ?string, phone: ?string, is_default: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'recipient' => $this->recipient,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'phone' => $this->phone,
            'is_default' => $this->isDefault,
        ];
    }

    private static function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
