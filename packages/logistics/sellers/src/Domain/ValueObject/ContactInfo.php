<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

use Logistics\Sellers\Domain\Exception\SellersException;

/**
 * Datos de contacto de un seller: persona responsable, correo y teléfono. Value object inmutable.
 *
 * El correo es obligatorio porque es el canal por el que el seller recibe notificaciones (citas,
 * recepciones, liquidaciones) vía outbox en fases posteriores. El teléfono es opcional.
 */
final readonly class ContactInfo
{
    private function __construct(
        public string $contactName,
        public string $email,
        public ?string $phone,
    ) {}

    public static function of(string $contactName, string $email, ?string $phone = null): self
    {
        $contactName = trim($contactName);
        if ($contactName === '') {
            throw SellersException::because('El nombre de contacto no puede estar vacío.');
        }

        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw SellersException::because("El correo de contacto no es válido: {$email}.");
        }

        $phone = $phone !== null ? trim($phone) : null;
        if ($phone === '') {
            $phone = null;
        }

        return new self($contactName, $email, $phone);
    }

    public function equals(self $other): bool
    {
        return $this->contactName === $other->contactName
            && $this->email === $other->email
            && $this->phone === $other->phone;
    }

    /**
     * @return array{contact_name: string, email: string, phone: ?string}
     */
    public function toArray(): array
    {
        return [
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }
}
