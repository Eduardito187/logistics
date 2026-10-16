<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Command\RecordGoodsReceipt\RecordGoodsReceipt;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Inbound\Presentation\Http\Request\RecordGoodsReceiptRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra la recepción física de un ASN con QC por línea (POST .../receipt). 202 de acuse.
 */
final class RecordGoodsReceiptController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RecordGoodsReceiptRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<array{sku: string, accepted: int, rejected: int, damaged: int, qc_outcome: string}> $lines */
            $lines = array_map(
                static fn (array $line): array => [
                    'sku' => (string) $line['sku'],
                    'accepted' => (int) $line['accepted'],
                    'rejected' => (int) $line['rejected'],
                    'damaged' => (int) $line['damaged'],
                    'qc_outcome' => (string) $line['qc_outcome'],
                ],
                array_values($request->array('lines')),
            );

            $sellerSkuRef = $request->string('seller_sku_ref')->toString();

            $this->commands->dispatch(new RecordGoodsReceipt(
                asnId: $request->string('asn_id')->toString(),
                lines: $lines,
                sellerSkuRef: $sellerSkuRef !== '' ? $sellerSkuRef : null,
            ));

            return $this->accepted(['asn_id' => $request->string('asn_id')->toString()]);
        });
    }
}
