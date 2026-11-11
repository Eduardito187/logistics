<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Print labels') }}</title>
    <style>
        /* Hoja imprimible de etiquetas: fondo blanco (contraste para el lector), una etiqueta por bloque.
           En impresora de pegatinas cada bloque es una etiqueta; en normal, varias por hoja. */
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #111; font-family: ui-monospace, 'IBM Plex Mono', monospace; }
        .toolbar { padding: .75rem 1rem; display: flex; gap: .6rem; align-items: center; background: #fff;
            border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; }
        .toolbar button { border: 0; border-radius: .5rem; padding: .5rem 1rem; font-weight: 600; cursor: pointer; }
        .toolbar .print { background: #e30613; color: #fff; }
        .toolbar .muted { color: #6b7280; font-size: .85rem; margin-left: auto; }
        .sheet { padding: 1rem; display: grid; gap: 1rem;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        .label { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px;
            break-inside: avoid; page-break-inside: avoid; text-align: center; }
        .label .qr { width: 180px; height: 180px; margin: 0 auto 6px; }
        .label .qr svg { display: block; width: 100%; height: 100%; }
        .label .sku { font-size: 13px; font-weight: 700; margin-top: 2px; }
        .label .bc { width: 100%; height: 48px; margin-top: 8px; }
        .label .code { font-size: 11px; letter-spacing: .04em; word-break: break-all; font-weight: 700; margin-top: 6px;
            border-top: 1px dashed #d0d0d0; padding-top: 6px; }
        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { padding: 0; gap: .25rem; }
            .label { border: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="print" onclick="window.print()">{{ __('Print') }}</button>
        <button onclick="window.close()">{{ __('Close') }}</button>
        <span class="muted">
            {{ count($labels) }} {{ __('label(s)') }} ·
            {{ $printer === 'sticker' ? __('Sticker printer') : __('Normal printer') }} ·
            {{ strtoupper($symbology) }}
        </span>
    </div>

    <div class="sheet">
        @forelse($labels as $l)
            <div class="label">
                @if($l['qr'] !== '')
                    <div class="qr">{!! $l['qr'] !!}</div>
                @endif
                <div class="sku">{{ $l['sku'] }}</div>
                @if($l['barcode'] !== '')
                    <div class="bc">{!! $l['barcode'] !!}</div>
                @endif
                <div class="code">{{ $l['label_id'] }}</div>
            </div>
        @empty
            <p>{{ __('No active labels to print.') }}</p>
        @endforelse
    </div>

    <script>
        // Abre el diálogo de impresión apenas carga (si hay etiquetas).
        if (document.querySelectorAll('.label').length > 0) {
            window.addEventListener('load', () => setTimeout(() => window.print(), 350));
        }
    </script>
</body>
</html>
