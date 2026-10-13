<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Contract;

use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;

/**
 * Puerto de persistencia de nodos. El dominio lo define; la infraestructura (Eloquent) lo implementa.
 *
 * nextIdentity() entrega el id antes de persistir, para que el agregado nazca ya con su identidad y
 * sus eventos puedan referenciarla.
 */
interface NodeRepository
{
    public function nextIdentity(): NodeId;

    public function save(Node $node): void;

    public function findById(NodeId $id): ?Node;

    public function findByCode(NodeCode $code): ?Node;

    /**
     * @return list<Node>
     */
    public function all(): array;
}
