<?php

declare(strict_types=1);

// Configuración del frente comercial (ADR 018/019/020). Laravel la carga automáticamente como `commerce`.
return [

    /*
    |--------------------------------------------------------------------------
    | Cancelación y expiración de órdenes impagas (ADR 020)
    |--------------------------------------------------------------------------
    |
    | Ventana de expiración (en minutos) de una orden Pending según el método del pago iniciado. El cron
    | `commerce:orders:expire-unpaid` calcula la ventana por método (o `default` si no hay pago o el
    | método no está mapeado) y, si venció, cancela la orden con source='cron', reason='unpaid_timeout'.
    | Las claves de método coinciden con las de `commerce_payments.method`; cada una es overridable por env.
    */
    'cancellation' => [
        'unpaid_expiry_minutes' => [
            // Las claves coinciden EXACTO con los valores de PaymentMethod (qr_bcb/bank_transfer/card/cash),
            // para que el cron haga match por método; `default` cubre órdenes sin pago iniciado.
            'qr_bcb' => (int) env('COMMERCE_EXPIRY_QR', 360),              // 6 h
            'bank_transfer' => (int) env('COMMERCE_EXPIRY_TRANSFER', 1440), // 24 h
            'card' => (int) env('COMMERCE_EXPIRY_CARD', 720),              // 12 h
            'cash' => (int) env('COMMERCE_EXPIRY_CASH', 1440),             // 24 h (contra entrega / ventanilla)
            'default' => (int) env('COMMERCE_EXPIRY_DEFAULT', 720),        // 12 h
        ],
    ],

];
