<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Contract;

use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Puerto de persistencia de facturas. La implementación vive en Infrastructure.
 *
 * La factura referencia su orden por `Uuid` genérico (invoicing no depende del contexto orders): por eso
 * `findByOrderId` recibe un `Uuid` y no un id tipado de otro contexto. Es la clave de idempotencia del
 * puente orders.order_paid → invoicing (una orden factura a lo sumo una vez).
 */
interface InvoiceRepository
{
    public function nextIdentity(): InvoiceId;

    /**
     * Upsert de la factura por id.
     */
    public function save(Invoice $invoice): void;

    public function findById(InvoiceId $id): ?Invoice;

    /**
     * Devuelve la factura asociada a una orden, o null si no hay ninguna.
     */
    public function findByOrderId(Uuid $orderId): ?Invoice;

    /**
     * @return list<Invoice>
     */
    public function all(): array;
}
