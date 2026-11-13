<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Query\ValidateVoucher;

use Logistics\Compensation\Application\ReadModel\VoucherValidityView;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Compensation\Domain\ValueObject\VoucherStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Valida un vale y devuelve su estado de canje. Un código inválido/inexistente devuelve `not_found` (genérico,
 * sin filtrar si existe): no se revela información a quien prueba códigos al azar (mitigación de fraude).
 *
 * @implements QueryHandler<ValidateVoucher, VoucherValidityView>
 */
final readonly class ValidateVoucherHandler implements QueryHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private Clock $clock,
    ) {}

    public function handle(Query $query): VoucherValidityView
    {
        assert($query instanceof ValidateVoucher);

        try {
            $code = VoucherCode::fromString($query->code);
        } catch (CompensationException) {
            return VoucherValidityView::notFound();
        }

        $voucher = $this->vouchers->findByCode($code);
        if ($voucher === null) {
            return VoucherValidityView::notFound();
        }

        if ($query->customerEmail !== null && ! $voucher->belongsTo($query->customerEmail)) {
            return VoucherValidityView::invalid('voucher_holder_mismatch', $voucher->status()->value, $voucher->amount());
        }

        $now = $this->clock->now();
        if ($voucher->isValidAt($now)) {
            return VoucherValidityView::valid($voucher->amount(), $voucher->expiresAt()->format(DATE_ATOM));
        }

        $reason = match ($voucher->status()) {
            VoucherStatus::Redeemed => 'voucher_redeemed',
            VoucherStatus::Revoked => 'voucher_revoked',
            default => 'voucher_expired',
        };

        return VoucherValidityView::invalid($reason, $voucher->status()->value, $voucher->amount());
    }
}
