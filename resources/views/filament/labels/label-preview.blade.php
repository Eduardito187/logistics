{{--
    Vista previa ESCANEABLE de la etiqueta para el admin (DisTrack OS): reproduce lo que imprime la Zebra —
    el QR firmado (con el qr_content EXACTO, se escanea igual que desde la app) y el Code128 del id del bulto
    — renderizados on-premise como SVG (CERO CDN, el payload firmado NO sale a ningún servicio externo).

    La "etiqueta" va sobre fondo BLANCO a propósito: el lector necesita contraste, igual que el papel térmico.
--}}
@php
    /** @var \Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord $record */
    $record = $getRecord();
    $renderer = app(\Logistics\Labels\Presentation\Support\LabelPreviewRenderer::class);
    $qr = $renderer->qr((string) $record->signed_json);
    $barcode = $renderer->barcode((string) $record->label_id);
    $revoked = (bool) $record->is_revoked;
@endphp

<div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">
    {{-- La etiqueta física (blanca, para escanear con contraste) --}}
    <div style="background:#ffffff;color:#111111;border-radius:12px;padding:16px 18px;width:300px;max-width:100%;
                box-shadow:0 1px 0 rgba(0,0,0,.04),0 8px 24px rgba(0,0,0,.35);position:relative;font-family:'IBM Plex Mono',ui-monospace,monospace">
        @if($revoked)
            <div style="position:absolute;top:10px;right:10px;background:#ffe3e5;color:#b4232c;font-weight:700;
                        font-size:10px;letter-spacing:.08em;padding:2px 8px;border-radius:999px;text-transform:uppercase">
                {{ __('Revoked') }}
            </div>
        @endif

        {{-- QR escaneable (negro sobre blanco), payload firmado exacto --}}
        <div style="display:flex;justify-content:center;padding:4px 0 8px">
            @if($qr !== '')
                <div style="width:200px;height:200px">{!! $qr !!}</div>
            @else
                <div style="color:#b4232c;font-size:12px">{{ __('Could not render the QR') }}</div>
            @endif
        </div>

        {{-- Campos legibles, como la etiqueta impresa --}}
        <div style="font-size:12px;line-height:1.5;border-top:1px dashed #d0d0d0;padding-top:8px">
            <div style="font-weight:700;font-size:13px">{{ $record->sku ?? '—' }}</div>
            <div style="color:#444">
                {{ __('Unit') }} {{ $record->unit_label ?? '—' }} · {{ __('Piece') }} {{ $record->piece_label ?? '—' }}
            </div>
            <div style="color:#444">
                {{ $record->origin ?? '—' }} <span style="color:#999">→</span> {{ $record->destination_label ?? '—' }}
            </div>
        </div>

        {{-- Code128 del id del bulto (igual al ^BC del ZPL) --}}
        <div style="margin-top:10px;border-top:1px dashed #d0d0d0;padding-top:8px;text-align:center">
            @if($barcode !== '')
                <div style="width:100%;height:54px">{!! $barcode !!}</div>
            @endif
            <div style="font-size:11px;letter-spacing:.04em;color:#222;word-break:break-all;margin-top:2px">
                {{ $record->label_id }}
            </div>
        </div>
    </div>

    {{-- Ayuda / escaneo manual --}}
    <div style="flex:1;min-width:200px;font-size:13px;line-height:1.6;color:var(--ck-text,#c9c9d4)">
        <div style="font-weight:600;margin-bottom:4px">{{ __('Scan from the app') }}</div>
        <p style="margin:0 0 .5rem">{{ __('This QR carries the exact signed payload the Zebra prints. Scan it with the app to resolve the physical package, just like scanning a printed label.') }}</p>
        <p style="margin:0;color:var(--ck-muted,#8a8a99)">{{ __('The Code128 below encodes the package id. The signed JSON and raw ZPL are in the section below (for auditing and printing).') }}</p>
    </div>
</div>
