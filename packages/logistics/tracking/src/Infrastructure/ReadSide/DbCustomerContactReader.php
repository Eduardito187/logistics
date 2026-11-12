<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\CustomerContactReader;
use Logistics\Tracking\Application\ReadModel\CustomerContact;

/**
 * Lee el contacto del cliente desde `shipments` por el query builder, en solo lectura y sin acoplar el
 * seguimiento al dominio de shipments (mismo patrón que los lectores cross-context de execution). El email
 * se normaliza (trim + minúsculas) y se descarta si no tiene formato válido: un dato basura nunca debe
 * terminar recibiendo una invitación. No registra el valor en logs (PII).
 */
final readonly class DbCustomerContactReader implements CustomerContactReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function forShipment(string $shipmentId): ?CustomerContact
    {
        $id = trim($shipmentId);
        if ($id === '') {
            return null;
        }

        $row = $this->connections->connection()
            ->table('shipments')
            ->where('id', $id)
            ->first(['customer_email', 'customer_phone']);

        if ($row === null) {
            return null;
        }

        return new CustomerContact(
            self::normalizeEmail($row->customer_email ?? null),
            self::phone($row->customer_phone ?? null),
        );
    }

    /**
     * Email normalizado (trim + minúsculas) y validado, o null. Público para que el backfill de
     * `customer_email` aplique exactamente la misma regla que la proyección de la entrega.
     */
    public static function normalizeEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $email = mb_strtolower(trim($value));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /** Teléfono recortado, o null si viene vacío. */
    private static function phone(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $phone = trim((string) $value);

        return $phone === '' ? null : $phone;
    }
}
