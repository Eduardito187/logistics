{{--
    Vista previa ESCANEABLE de la etiqueta para el admin (DisTrack OS): reproduce lo que imprime la Zebra.
    Respeta la SIMBOLOGÍA configurada (setting `labels.symbology`, versionado): dibuja SOLO el QR firmado, o
    SOLO el Code128 (Zebra), o ambos — nunca más de lo configurado. Debajo SIEMPRE va el código único de la
    etiqueta (`label_id`). Todo on-premise como SVG (CERO CDN: el payload firmado NO sale a ningún servicio).

    La "etiqueta" va sobre fondo BLANCO a propósito: el lector necesita contraste, igual que el papel térmico.
--}}
@php
    /** @var \Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord $record */
    $record = $getRecord();
    $renderer = app(\Logistics\Labels\Presentation\Support\LabelPreviewRenderer::class);

    // Simbología VIGENTE AHORA (setting global `labels.symbology`): el preview refleja el formato elegido en
    // Configuración → Formato de etiqueta, para verlo cambiar al instante. Al reimprimir, el ZPL se regenera
    // con esta misma simbología. Tolerante.
    $symbology = app(\Logistics\Labels\Domain\Contract\LabelSymbologyProvider::class)->current(new \DateTimeImmutable);

    $showQr = $symbology->includesQr();
    $showBarcode = $symbology->includesBarcode();

    $qr = $showQr ? $renderer->qr((string) $record->signed_json) : '';
    $barcode = $showBarcode ? $renderer->barcode((string) $record->label_id) : '';
@endphp

<div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">
    {{-- La etiqueta física (blanca, para escanear con contraste) --}}
    <div style="background:#ffffff;color:#111111;border-radius:12px;padding:16px 18px;width:300px;max-width:100%;
                box-shadow:0 1px 0 rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.35);position:relative;font-family:'IBM Plex Mono',ui-monospace,monospace">
        {{-- QR escaneable (negro sobre blanco), payload firmado exacto — solo si la simbología lo incluye --}}
        @if($showQr)
            <div style="display:flex;justify-content:center;padding:4px 0 8px">
                @if($qr !== '')
                    <div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;line-height:0">{!! $qr !!}</div>
                @else
                    <div style="color:#b4232c;font-size:12px">{{ __('Could not render the QR') }}</div>
                @endif
            </div>
        @endif

        {{-- SKU del producto: único campo legible (más el código único abajo). Nada de unidad/pieza/ruta que no se pidió. --}}
        <div style="font-size:12px;line-height:1.5;text-align:center;{{ $showQr ? 'border-top:1px dashed #d0d0d0;padding-top:8px' : '' }}">
            <div style="font-weight:700;font-size:13px">{{ $record->sku ?? __('Product') }}</div>
        </div>

        {{-- Code128 del id del bulto (igual al ^BC del ZPL) — solo si la simbología lo incluye --}}
        @if($showBarcode && $barcode !== '')
            <div style="margin-top:10px;border-top:1px dashed #d0d0d0;padding-top:8px;text-align:center">
                <div style="width:100%;height:54px">{!! $barcode !!}</div>
            </div>
        @endif

        {{-- CÓDIGO ÚNICO de la etiqueta: SIEMPRE abajo (lo lee el lector/la app como respaldo del QR/Zebra). --}}
        <div style="margin-top:8px;{{ ($showBarcode && $barcode !== '') ? '' : 'border-top:1px dashed #d0d0d0;padding-top:8px;' }}text-align:center;
                    font-size:11px;letter-spacing:.04em;color:#222;word-break:break-all;font-weight:600">
            {{ $record->label_id }}
        </div>
    </div>

    {{-- Ayuda / escaneo --}}
    <div style="flex:1;min-width:200px;font-size:13px;line-height:1.6;color:var(--ck-text,#c9c9d4)">
        <div style="font-weight:600;margin-bottom:4px">{{ __('Scan from the app or the web') }}</div>
        <p style="margin:0 0 .5rem">
            {{ __('Symbology') }}:
            <strong>{{ $showQr && $showBarcode ? __('QR + Zebra') : ($showQr ? 'QR' : 'Zebra (Code128)') }}</strong>
        </p>
        <p style="margin:0;color:var(--ck-muted,#8a8a99)">{{ __('Scanning either the QR or the Zebra barcode resolves the same package: both carry the label code shown below. Change the symbology in Settings → Label format.') }}</p>
    </div>
</div>
