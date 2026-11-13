{{--
    Vista previa ESCANEABLE de UNA etiqueta emitida del envío, para la pestaña "Seguimiento" del hub 360.
    Reutiliza el renderer de simbologías (LabelPreviewRenderer, CERO CDN): dibuja el QR firmado y/o el
    Code128 como SVG inline según la simbología VIGENTE que recibe el partial. Muestra el producto (nombre +
    SKU), el destino legible, la unidad/pieza "N de M" y el estado de impresión. NUNCA UUID ni la palabra
    "bulto": un producto se nombra siempre por su nombre + SKU.

    @param  array{label_id: string, sku: string, product_name: string|null, qr_content: string, destination_name: string|null, unit_index: int, unit_total: int, piece_index: int, piece_total: int, printed: bool}  $label
    @param  bool  $showQr
    @param  bool  $showBarcode
    @param  \Logistics\Labels\Presentation\Support\LabelPreviewRenderer  $renderer
--}}
@php
    $qr = $showQr ? $renderer->qr($label['qr_content']) : '';
    $barcode = $showBarcode ? $renderer->barcode($label['label_id']) : '';

    $productName = $label['product_name'] ?? null;
    $productName = is_string($productName) && $productName !== '' ? $productName : __('Product');
    $sku = $label['sku'] !== '' ? $label['sku'] : '—';
@endphp

<div class="ck-card" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">
    {{-- La etiqueta física (blanca, para escanear con contraste), igual que la que imprime la Zebra. --}}
    <div style="background:#ffffff;color:#111111;border-radius:12px;padding:16px 18px;width:240px;max-width:100%;
                box-shadow:0 1px 0 rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.35);font-family:'IBM Plex Mono',ui-monospace,monospace">
        @if($showQr)
            <div style="display:flex;justify-content:center;padding:4px 0 8px">
                @if($qr !== '')
                    <div style="width:170px;height:170px;display:flex;align-items:center;justify-content:center;line-height:0">{!! $qr !!}</div>
                @else
                    <div style="color:#b4232c;font-size:12px">{{ __('Could not render the QR') }}</div>
                @endif
            </div>
        @endif

        <div style="font-size:12px;line-height:1.5;text-align:center;{{ $showQr ? 'border-top:1px dashed #d0d0d0;padding-top:8px' : '' }}">
            <div style="font-weight:700;font-size:13px">{{ $productName }}</div>
            <div>SKU {{ $sku }}</div>
        </div>

        @if($showBarcode && $barcode !== '')
            <div style="margin-top:10px;border-top:1px dashed #d0d0d0;padding-top:8px;text-align:center">
                <div style="width:100%;height:48px">{!! $barcode !!}</div>
            </div>
        @endif

        {{-- CÓDIGO ÚNICO de la etiqueta: SIEMPRE abajo (respaldo del QR/Zebra). No es un UUID de dominio. --}}
        <div style="margin-top:8px;{{ ($showBarcode && $barcode !== '') ? '' : 'border-top:1px dashed #d0d0d0;padding-top:8px;' }}text-align:center;
                    font-size:11px;letter-spacing:.04em;color:#222;word-break:break-all;font-weight:600">
            {{ $label['label_id'] }}
        </div>
    </div>

    {{-- Datos legibles de la etiqueta (producto + SKU, destino, unidad/pieza, estado). --}}
    <div style="flex:1;min-width:170px">
        <dl class="ck-dl">
            <dt>{{ __('Product') }}</dt><dd>{{ $productName }}</dd>
            <dt>{{ __('SKU') }}</dt><dd class="ck-mono">{{ $sku }}</dd>
            <dt>{{ __('Destination') }}</dt><dd>{{ $label['destination_name'] ?: '—' }}</dd>
            <dt>{{ __('Unit') }}</dt><dd>{{ $label['unit_index'] }} / {{ $label['unit_total'] }}</dd>
            <dt>{{ __('Piece') }}</dt><dd>{{ $label['piece_index'] }} / {{ $label['piece_total'] }}</dd>
            <dt>{{ __('Status') }}</dt>
            <dd><span class="ck-pill {{ $label['printed'] ? 'ck-pill--positive' : '' }}">{{ $label['printed'] ? __('Printed') : __('Pending') }}</span></dd>
        </dl>
    </div>
</div>
