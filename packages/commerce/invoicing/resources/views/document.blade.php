{{--
    Documento fiscal INTERNO de la factura (EC-F0-10). HTML imprimible, sin recursos externos ni CDN:
    todos los estilos van inline en este mismo archivo. Los textos del documento van en español por ser
    un comprobante boliviano. Los montos llegan en centavos (enteros) y se formatean a 2 decimales.

    PENDIENTE EXTERNO: la emisión fiscal válida ante el SIN de Bolivia (factura electrónica: CUF/CUFD,
    firma digital, código de control) requiere integración con credenciales del SIN que NO están
    disponibles. Este es el comprobante interno; la validez fiscal se resolverá por el conector del SIN.
--}}
@php
    /** @var array{id: string, order_id: string, number: ?string, customer_name: string, customer_tax_id: ?string, currency: string, lines: array<int, array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}>, net_cents: int, iva_cents: int, total_cents: int, status: string, issued_at: ?string} $invoice */
    /** @var array<string, string> $issuer */
    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.');
    $currency = $invoice['currency'] ?? 'BOB';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura {{ $invoice['number'] ?? 'S/N' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif;
            color: #1a1a1a;
            background: #f4f4f5;
            margin: 0;
            padding: 24px 16px;
            font-size: 14px;
            line-height: 1.5;
        }
        .document {
            max-width: 760px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #d4d4d8;
            padding: 32px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 16px;
            margin-bottom: 20px;
            gap: 16px;
        }
        .issuer-name { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
        .issuer-meta { margin: 0; color: #52525b; font-size: 13px; }
        .doc-box { text-align: right; }
        .doc-title { font-size: 13px; letter-spacing: 1px; text-transform: uppercase; color: #52525b; margin: 0; }
        .doc-number { font-size: 22px; font-weight: 700; margin: 2px 0 0; }
        .status {
            display: inline-block;
            margin-top: 8px;
            padding: 2px 10px;
            border: 1px solid #a1a1aa;
            border-radius: 999px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .parties { display: flex; gap: 24px; margin-bottom: 20px; flex-wrap: wrap; }
        .party { flex: 1 1 220px; }
        .party h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #52525b; margin: 0 0 4px; }
        .party p { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        thead th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #52525b;
            border-bottom: 1px solid #d4d4d8;
            padding: 8px 6px;
        }
        tbody td { padding: 8px 6px; border-bottom: 1px solid #ededed; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 280px; }
        .totals tr td { padding: 6px 6px; border: none; }
        .totals .label { color: #52525b; }
        .totals .grand td { border-top: 2px solid #1a1a1a; font-size: 16px; font-weight: 700; padding-top: 10px; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px dashed #d4d4d8; color: #71717a; font-size: 12px; }
        .footer strong { color: #52525b; }
        @media print {
            body { background: #ffffff; padding: 0; }
            .document { border: none; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="document">
        <div class="header">
            <div>
                <p class="issuer-name">{{ $issuer['business_name'] ?? 'DISMAC S.A.' }}</p>
                @if (!empty($issuer['nit']))
                    <p class="issuer-meta">NIT: {{ $issuer['nit'] }}</p>
                @endif
                @if (!empty($issuer['address']))
                    <p class="issuer-meta">{{ $issuer['address'] }}</p>
                @endif
            </div>
            <div class="doc-box">
                <p class="doc-title">Factura</p>
                <p class="doc-number">N.° {{ $invoice['number'] ?? 'S/N' }}</p>
                <span class="status">{{ $invoice['status'] }}</span>
            </div>
        </div>

        <div class="parties">
            <div class="party">
                <h2>Cliente</h2>
                <p>{{ $invoice['customer_name'] }}</p>
                <p>NIT/CI: {{ $invoice['customer_tax_id'] ?? 'Sin dato' }}</p>
            </div>
            <div class="party">
                <h2>Datos</h2>
                <p>Fecha de emisión: {{ $invoice['issued_at'] ?? 'Pendiente' }}</p>
                <p>Moneda: {{ $currency }}</p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th class="num">Cantidad</th>
                    <th class="num">P. unitario</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice['lines'] as $line)
                    <tr>
                        <td>{{ $line['description'] }}</td>
                        <td class="num">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $money($line['unit_price_cents']) }}</td>
                        <td class="num">{{ $money($line['line_total_cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tbody>
                <tr>
                    <th scope="row" class="label">Subtotal neto</th>
                    <td class="num">{{ $money($invoice['net_cents']) }} {{ $currency }}</td>
                </tr>
                <tr>
                    <th scope="row" class="label">IVA (13 %)</th>
                    <td class="num">{{ $money($invoice['iva_cents']) }} {{ $currency }}</td>
                </tr>
                <tr class="grand">
                    <th scope="row">Total</th>
                    <td class="num">{{ $money($invoice['total_cents']) }} {{ $currency }}</td>
                </tr>
            </tbody>
        </table>

        <div class="footer">
            <p>
                El precio al público incluye el IVA (13 % por dentro); el neto y el impuesto se desglosan
                sobre ese total. El Impuesto a las Transacciones (IT, 3 %) no se desglosa por separado en este
                comprobante.
            </p>
            <p>
                <strong>Documento interno.</strong> La emisión fiscal válida ante el Servicio de Impuestos
                Nacionales (SIN) de Bolivia —factura electrónica: CUF/CUFD, firma digital y código de
                control— es un pendiente externo que requiere la integración con el servicio del SIN.
            </p>
        </div>
    </div>
</body>
</html>
