<?php

declare(strict_types=1);

namespace Logistics\Execution\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Execution\Domain\Contract\FailureReasonRepository;
use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;

/**
 * Siembra los 5 motivos de "no se pudo entregar" de fábrica, ACTIVOS, sin exigencias, con sus codes de hoy
 * (los mismos que la app trae como respaldo) y sort_order 0..4.
 *
 * Idempotente y UUID-estable: usa existsByCode por motivo; una re-ejecución no crea duplicados ni regenera
 * los UUID (no rompe deep-links). Es la fuente única que reusan el flujo de seeders demo (lo llama
 * ExecutionDemoSeeder) y el comando `logistics:execution:seed-failure-reasons`.
 */
final class FailureReasonSeeder extends Seeder
{
    /**
     * Motivos de fábrica: [code, label] en el orden en que se muestran (sort_order = índice).
     *
     * @var list<array{code: string, label: string}>
     */
    public const array DEFAULTS = [
        ['code' => 'CUSTOMER_ABSENT', 'label' => 'Customer absent'],
        ['code' => 'WRONG_ADDRESS', 'label' => 'Wrong address'],
        ['code' => 'REJECTED', 'label' => 'Rejected by customer'],
        ['code' => 'DAMAGED', 'label' => 'Damaged'],
        ['code' => 'OTHER', 'label' => 'Other'],
    ];

    public function __construct(
        private readonly FailureReasonRepository $reasons,
    ) {}

    public function run(): void
    {
        foreach (self::DEFAULTS as $sortOrder => $default) {
            $code = FailureReasonCode::fromString($default['code']);
            if ($this->reasons->existsByCode($code)) {
                continue;
            }

            $this->reasons->save(FailureReason::create(
                $this->reasons->nextIdentity(),
                $code,
                $default['label'],
                active: true,
                requiresPhoto: false,
                requiresNote: false,
                noteLabel: null,
                sortOrder: $sortOrder,
            ));
        }
    }
}
