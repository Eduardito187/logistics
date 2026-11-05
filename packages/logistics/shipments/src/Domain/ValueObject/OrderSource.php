<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Fuente de origen del pedido. La plataforma vive FUERA de SAP (ADR 002): SAP es una fuente más,
 * igual que Magento, las tiendas, la carga manual o un Excel. Todas se normalizan al modelo propio.
 */
enum OrderSource: string
{
    case Sap = 'SAP';

    /**
     * Frente comercial propio (OMS de `packages/commerce`): el pedido entra a logística cuando la orden
     * se paga (ADR 018/020). Es la fuente con la que el puente orders → shipments ingresa y halla los
     * envíos, por el par ('commerce', orderId).
     */
    case Commerce = 'commerce';

    case Magento = 'MAGENTO';

    case Store = 'STORE';

    case Manual = 'MANUAL';

    case Excel = 'EXCEL';
}
