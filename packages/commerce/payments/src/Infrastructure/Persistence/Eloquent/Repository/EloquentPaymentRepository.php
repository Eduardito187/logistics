<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Payments\Domain\Contract\PaymentRepository;
use Commerce\Payments\Domain\Model\Payment;
use Commerce\Payments\Domain\ValueObject\PaymentId;
use Commerce\Payments\Infrastructure\Persistence\Eloquent\Mapper\PaymentMapper;
use Commerce\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de pagos. `save` es un upsert por id del pago.
 */
final readonly class EloquentPaymentRepository implements PaymentRepository
{
    public function __construct(
        private PaymentMapper $mapper = new PaymentMapper,
    ) {}

    public function nextIdentity(): PaymentId
    {
        return PaymentId::generate();
    }

    public function save(Payment $payment): void
    {
        $row = $this->mapper->toRow($payment);
        PaymentRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(PaymentId $id): ?Payment
    {
        $record = PaymentRecord::query()->find($id->toString());

        return $record instanceof PaymentRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByOrderId(Uuid $orderId): ?Payment
    {
        $record = PaymentRecord::query()
            ->where('order_id', $orderId->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $record instanceof PaymentRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(PaymentRecord::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentRecord $record): Payment => $this->mapper->toDomain($record))
            ->all());
    }
}
