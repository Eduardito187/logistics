<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetAsn;

use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un ASN por su id, o null si no existe.
 *
 * @implements QueryHandler<GetAsn, AsnView|null>
 */
final readonly class GetAsnHandler implements QueryHandler
{
    public function __construct(
        private AsnRepository $asns,
    ) {}

    public function handle(Query $query): ?AsnView
    {
        assert($query instanceof GetAsn);

        $asn = $this->asns->findById(AsnId::fromString($query->asnId));

        return $asn !== null ? AsnView::fromDomain($asn) : null;
    }
}
