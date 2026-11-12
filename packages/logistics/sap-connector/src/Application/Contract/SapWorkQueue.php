<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Contract;

use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;

/**
 * Puerto de encolado del trabajo que habla con SAP. CADA trabajo que toca SAP corre en la cola dedicada `sap`
 * (workers propios), nunca dentro de un request de usuario ni en la cola `outbox` compartida: así la
 * lentitud o caída de SAP no frena a nadie más. Infrastructure lo implementa con jobs de Laravel.
 */
interface SapWorkQueue
{
    /**
     * Encola el envío de un aviso a SAP ya registrado.
     */
    public function queueNotification(SapNotificationId $id): void;

    /**
     * Encola una corrida de reconciliación de traspasos (Failed + Pending estancados).
     */
    public function queueTransferDispatch(): void;

    /**
     * Encola la lectura de pedidos pendientes desde SAP.
     */
    public function queueOrdersImport(): void;
}
