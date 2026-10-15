<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\HelperId;

/**
 * Puerto de persistencia de ayudantes. La implementación vive en Infrastructure.
 */
interface HelperRepository
{
    public function nextIdentity(): HelperId;

    public function save(Helper $helper): void;

    public function findById(HelperId $id): ?Helper;

    /**
     * @return list<Helper>
     */
    public function all(): array;
}
