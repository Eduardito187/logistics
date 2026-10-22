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
     * Ayudantes por id en UNA consulta (evita N+1 al enriquecer una tripulación). Los ids inexistentes
     * se omiten; la clave del mapa es el id en texto.
     *
     * @param  list<string>          $ids
     * @return array<string, Helper>
     */
    public function findByIds(array $ids): array;

    /**
     * @return list<Helper>
     */
    public function all(): array;
}
