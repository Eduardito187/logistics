<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Fuente de origen del pedido. La plataforma vive FUERA de SAP (ADR 002): SAP es una fuente más,
 * igual que Magento, las tiendas, la carga manual o un Excel. Todas se normalizan al modelo propio.
 */
enum OrderSource: string
{
    use CaseInsensitiveEnum;

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

    /**
     * Traspaso INTERNO de mercadería (reposición CD → tienda, CD → CD): no hay cliente final que retire ni
     * reciba. Su envío va a un nodo y se recibe en «Recepción de mercadería», nunca en «Recojo en tienda».
     */
    case Transfer = 'TRANSFER';

    /**
     * ¿Es un movimiento interno de mercadería (sin cliente final)? Hoy solo el traspaso.
     */
    public function isInternalTransfer(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Valores de las fuentes internas (para los filtros SQL del lado de lectura).
     *
     * @return list<string>
     */
    public static function internalTransferValues(): array
    {
        return array_values(array_map(
            static fn (self $source): string => $source->value,
            array_filter(self::cases(), static fn (self $source): bool => $source->isInternalTransfer()),
        ));
    }
}
