<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\ResolveLabelUnit;

use Logistics\Labels\Application\Contract\LabelUnitResolver;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Delegа la resolución etiqueta→bulto al {@see LabelUnitResolver} (lectura cross-context tolerante).
 *
 * @implements QueryHandler<ResolveLabelUnit, LabelUnitView>
 */
final readonly class ResolveLabelUnitHandler implements QueryHandler
{
    public function __construct(
        private LabelUnitResolver $resolver,
    ) {}

    public function handle(Query $query): LabelUnitView
    {
        assert($query instanceof ResolveLabelUnit);

        return $this->resolver->resolve($query->labelId);
    }
}
