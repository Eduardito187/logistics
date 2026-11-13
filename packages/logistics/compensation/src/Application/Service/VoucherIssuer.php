<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Service;

use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\Contract\CompensationPolicyProvider;
use Logistics\Compensation\Domain\Contract\VoucherRepository;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\Model\CompensationCase;
use Logistics\Compensation\Domain\Model\Voucher;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Servicio de aplicación que emite el vale de un caso APROBADO: genera un código único (reintenta ante
 * colisión), calcula el vencimiento desde la política del disparador y persiste el vale con sus eventos al
 * outbox en la MISMA transacción. Lo usan el IssueVoucherHandler y el auto-emitido del alta de un caso.
 *
 * Idempotente por caso: si el caso ya tiene vale, lo devuelve sin crear otro.
 */
final readonly class VoucherIssuer
{
    private const int MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private VoucherRepository $vouchers,
        private CompensationPolicyProvider $policyProvider,
        private RecordDomainEvents $recordEvents,
        private CaseAuditLog $auditLog,
        private Clock $clock,
    ) {}

    public function issueFor(CompensationCase $case, ?string $holderEmail): Voucher
    {
        $existing = $this->vouchers->findByCaseId($case->id());
        if ($existing !== null) {
            return $existing;
        }

        $policy = $this->policyProvider->current();
        $validityDays = $policy->forTrigger($case->trigger())->validityDays;
        $expiresAt = $this->clock->now()->modify("+{$validityDays} days");

        $voucher = Voucher::issue(
            $this->vouchers->nextIdentity(),
            $case->id(),
            $this->freshCode(),
            $case->effective(),
            $holderEmail,
            $expiresAt,
            $case->orderReference(),
            $case->trigger()->value,
        );

        $this->vouchers->save($voucher);
        $this->recordEvents->record($voucher->releaseEvents(), 'compensation.voucher');
        $this->auditLog->record(
            $case->id()->toString(),
            $voucher->id()->toString(),
            'issued',
            CompensationCase::SYSTEM_ACTOR,
            ['amount_cents' => $voucher->amount()->cents, 'expires_at' => $expiresAt->format(DATE_ATOM)],
        );

        return $voucher;
    }

    /**
     * Genera un código no usado. El índice único de `code` es la garantía última; acá se reintenta para no
     * fallar ante una colisión astronómicamente improbable.
     */
    private function freshCode(): VoucherCode
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = VoucherCode::generate();
            if ($this->vouchers->findByCode($code) === null) {
                return $code;
            }
        }

        throw CompensationException::because('No se pudo generar un código de vale único.');
    }
}
