<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Invoicing\Domain\Contract\InvoiceRepository;
use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Mapper\InvoiceMapper;
use Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Model\InvoiceRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de facturas. `save` es un upsert por id de la factura.
 *
 * La idempotencia por orden se garantiza en el handler (findByOrderId antes de crear) y la refuerza el
 * índice único de `order_id` en la tabla.
 */
final readonly class EloquentInvoiceRepository implements InvoiceRepository
{
    public function __construct(
        private InvoiceMapper $mapper = new InvoiceMapper,
    ) {}

    public function nextIdentity(): InvoiceId
    {
        return InvoiceId::generate();
    }

    public function save(Invoice $invoice): void
    {
        $row = $this->mapper->toRow($invoice);
        InvoiceRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(InvoiceId $id): ?Invoice
    {
        $record = InvoiceRecord::query()->find($id->toString());

        return $record instanceof InvoiceRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByOrderId(Uuid $orderId): ?Invoice
    {
        $record = InvoiceRecord::query()->where('order_id', $orderId->value)->first();

        return $record instanceof InvoiceRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(InvoiceRecord::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InvoiceRecord $record): Invoice => $this->mapper->toDomain($record))
            ->all());
    }
}
