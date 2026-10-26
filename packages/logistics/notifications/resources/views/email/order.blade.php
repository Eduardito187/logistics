{{-- Plantilla HTML del email transaccional al cliente. Muestra el cuerpo del mensaje y, debajo, el
     listado de productos del pedido con su imagen (thumbnail), nombre, SKU y cantidad. Estilos en línea para
     máxima compatibilidad con clientes de correo. La marca es DisTrack: rojo #E30613 como acento.
     El logo del encabezado se referencia por URL absoluta (no inline) y usa el PNG de la marca, que
     renderiza en todos los clientes de correo (muchos no soportan SVG); el wordmark "DisTrack" va como
     texto para garantizar la marca aunque el cliente bloquee imágenes. --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background-color:#ffffff;padding:20px 28px;border-top:4px solid #E30613;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding-right:12px;vertical-align:middle;">
                                        {{-- Logo de la marca por URL absoluta (PNG = máxima compatibilidad de correo). --}}
                                        <img src="{{ config('app.url') }}/brand/distrack/png/favicon-512.png" alt="DisTrack" width="40" height="40" style="display:block;width:40px;height:40px;">
                                    </td>
                                    <td style="vertical-align:middle;">
                                        <span style="font-size:22px;font-weight:bold;letter-spacing:0.5px;color:#141414;">Dis<span style="color:#E30613;">Track</span></span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 16px;font-size:20px;color:#111827;">{{ $subject }}</h1>
                            <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#374151;">{!! nl2br(e($body)) !!}</p>

                            @if (!empty($products))
                                <h2 style="margin:24px 0 12px;font-size:16px;color:#111827;border-bottom:2px solid #E30613;padding-bottom:6px;">Tu pedido</h2>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                    @foreach ($products as $product)
                                        <tr>
                                            <td width="72" style="padding:8px 12px 8px 0;vertical-align:top;">
                                                @if (!empty($product['imageUrl']))
                                                    <img src="{{ $product['imageUrl'] }}" alt="{{ $product['name'] }}" width="64" height="64" style="display:block;width:64px;height:64px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                                                @else
                                                    <div style="width:64px;height:64px;border-radius:6px;background-color:#f3f4f6;border:1px solid #e5e7eb;"></div>
                                                @endif
                                            </td>
                                            <td style="padding:8px 0;vertical-align:top;">
                                                <span style="display:block;font-size:14px;color:#111827;font-weight:bold;">{{ $product['name'] }}</span>
                                                @if (!empty($product['sku']))
                                                    <span style="display:block;font-size:12px;color:#6b7280;margin-top:2px;">SKU: {{ $product['sku'] }}</span>
                                                @endif
                                                <span style="display:block;font-size:13px;color:#6b7280;margin-top:4px;">Cantidad: {{ $product['qty'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px;background-color:#F3F3F1;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:12px;color:#6b7280;">Pedido {{ $shipmentId }} · Este es un mensaje automático de DisTrack.</p>
                            <p style="margin:6px 0 0;font-size:11px;font-weight:bold;letter-spacing:1px;color:#E30613;">DE ANDÉN A PUERTA.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
