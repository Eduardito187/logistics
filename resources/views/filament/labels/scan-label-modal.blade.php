@php
    /**
     * Contenido del MODAL del escáner de etiquetas. Cámara + BarcodeDetector nativo (QR y Code128), sin
     * dependencias externas (CERO CDN). Resuelve por /cockpit/label/resolve (misma lógica de siempre).
     * Respaldo: tipear/pegar el código único a mano.
     *
     * El componente va como OBJETO INLINE de `x-data` (como los mapas del repo): el contenido del modal lo
     * inyecta Livewire por morph y un `<script>` suelto NO se ejecutaría. Los textos van en inglés (regla 5)
     * y entran por `Js::from` (NO `@js`, que no compila en atributos de componentes `<x-...>`, gotcha del repo).
     */
    $scanConfig = \Illuminate\Support\Js::from([
        'resolveUrl' => route('cockpit.label.resolve'),
        'strings' => [
            'scanning' => __('Scanning…'),
            'cameraNoScan' => __('Camera on — scan unavailable, use the code field.'),
            'unsupported' => __('This browser cannot scan with the camera; type or paste the label code below.'),
            'cameraError' => __('Could not open the camera. Check permissions or use the code field.'),
            'resolveError' => __('Error resolving the label.'),
        ],
    ]);
@endphp

{{-- `wire:ignore`: una vez montado el modal, Alpine maneja todo (el escaneo no re-renderiza Livewire), así el
     <video> y el stream no se ven afectados por ningún morph. `close-modal` (X, Escape, backdrop o Close) y la
     red de seguridad del MutationObserver apagan la cámara y liberan los tracks al cerrar o al navegar (SPA). --}}
<div
    wire:ignore
    x-data="{
        cfg: {{ $scanConfig }},
        cameraOn: false, scanning: false, loading: false, error: '', code: '', result: null,
        stream: null, detector: null, observer: null, hint: '',
        init() {
            this.hint = this.cfg.strings.scanning;
            // Si el navegador no soporta BarcodeDetector, igual sirve el código a mano.
            if (!('BarcodeDetector' in window)) { this.error = this.cfg.strings.unsupported; }
            // Red de seguridad: si el nodo se remueve (navegación SPA o cierre no capturado por
            // `close-modal`), apagar la cámara y liberar los tracks igual.
            this.observer = new MutationObserver(() => {
                if (!document.body.contains(this.$root)) { this.cleanup(); }
            });
            this.observer.observe(document.body, { childList: true, subtree: true });
        },
        // El switch arranca o para la cámara. Si getUserMedia falla, el switch vuelve a apagado.
        toggleCamera(on) { on ? this.start() : this.stop(); },
        async start() {
            this.error = ''; this.result = null;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();
                this.cameraOn = true; this.scanning = true;
                if ('BarcodeDetector' in window) {
                    this.detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128'] });
                    this.loop();
                } else {
                    this.hint = this.cfg.strings.cameraNoScan;
                }
            } catch (e) {
                this.error = this.cfg.strings.cameraError;
                this.cameraOn = false; this.scanning = false;
            }
        },
        async loop() {
            if (!this.scanning || !this.detector) { return; }
            try {
                const codes = await this.detector.detect(this.$refs.video);
                if (codes && codes.length && codes[0].rawValue) { this.onCode(codes[0].rawValue); return; }
            } catch (e) { /* frame sin lectura: se reintenta */ }
            requestAnimationFrame(() => this.loop());
        },
        onCode(raw) { this.code = raw; this.stop(); this.resolve(raw); },
        // Apaga la cámara y LIBERA los tracks (no deja la cámara prendida tras leer o apagar).
        stop() {
            this.scanning = false; this.cameraOn = false; this.detector = null;
            if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
            if (this.$refs.video) { this.$refs.video.srcObject = null; }
        },
        // Cierre del modal o navegación: parar el stream y soltar el observer.
        cleanup() {
            this.stop();
            if (this.observer) { this.observer.disconnect(); this.observer = null; }
        },
        manual() { const c = this.code.trim(); if (c) { this.resolve(c); } },
        async resolve(code) {
            this.loading = true; this.result = null; this.error = '';
            try {
                const r = await fetch(this.cfg.resolveUrl + '?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' } });
                this.result = await r.json();
            } catch (e) { this.error = this.cfg.strings.resolveError; }
            this.loading = false;
        },
    }"
    @close-modal.window="cleanup()"
    class="dtk-scan"
>
    <style>
        .dtk-scan__stage { position: relative; aspect-ratio: 4 / 3; width: 100%; overflow: hidden;
            border-radius: 1rem; background: #0a0a0a; border: 1px solid rgba(255,255,255,.1); }
        .dtk-scan__stage video { width: 100%; height: 100%; object-fit: cover; }
        .dtk-scan__reticle { position: absolute; inset: 18% 14%; border: 2px solid rgba(227,6,19,.9);
            border-radius: .75rem; box-shadow: 0 0 0 9999px rgba(0,0,0,.35); pointer-events: none; }
        .dtk-scan__hint { position: absolute; inset-inline: 0; bottom: .6rem; text-align: center;
            font-size: .8rem; color: #e4e4e7; text-shadow: 0 1px 2px #000; }
        .dtk-scan__offhint { position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; text-align: center; padding: 0 1.25rem; font-size: .85rem; color: #a1a1aa; }
        .dtk-scan__switchrow { display: flex; align-items: center; gap: .6rem; margin-top: .9rem; }
        .dtk-scan__switch { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
        .dtk-scan__switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .dtk-scan__track { width: 2.6rem; height: 1.5rem; border-radius: 999px; background: rgba(120,120,130,.4);
            transition: background .15s; position: relative; flex: none; }
        .dtk-scan__track::after { content: ''; position: absolute; top: .18rem; left: .18rem; width: 1.14rem;
            height: 1.14rem; border-radius: 50%; background: #fff; transition: transform .15s; }
        .dtk-scan__switch input:checked + .dtk-scan__track { background: #e30613; }
        .dtk-scan__switch input:checked + .dtk-scan__track::after { transform: translateX(1.1rem); }
        .dtk-scan__switch input:focus-visible + .dtk-scan__track { outline: 2px solid #e30613; outline-offset: 2px; }
        .dtk-scan__switchlabel { font-weight: 600; font-size: .9rem; }
        .dtk-scan__manual { display: flex; gap: .5rem; margin-top: 1rem; }
        .dtk-scan__manual input { flex: 1; border-radius: .6rem; border: 1px solid rgba(120,120,130,.4);
            background: rgba(0,0,0,.25); padding: .55rem .8rem; font: inherit; }
        .dtk-scan__btn { border-radius: .6rem; padding: .55rem 1rem; font-weight: 600; font-size: .85rem;
            cursor: pointer; border: 1px solid rgba(120,120,130,.4); background: transparent; }
        .dtk-scan__card { margin-top: 1.25rem; border-radius: 1rem; border: 1px solid rgba(120,120,130,.25);
            padding: 1.1rem 1.25rem; }
        .dtk-scan__ok { color: #16a34a; } .dtk-scan__bad { color: #dc2626; }
        .dtk-scan__kv { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem; margin-top: .6rem;
            font-size: .9rem; } .dtk-scan__kv dt { color: #a1a1aa; } .dtk-scan__kv dd { margin: 0; font-weight: 600; }
        .dtk-scan__err { margin-top: .8rem; color: #dc2626; font-size: .85rem; }
        .dtk-scan__sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    </style>

    <p style="color:#a1a1aa;margin-bottom:1rem;font-size:.9rem">
        {{ __('Point the camera at the label QR or the Zebra barcode — either one resolves the same package. Works with any of the symbologies.') }}
    </p>

    <div class="dtk-scan__stage">
        <video x-ref="video" playsinline muted></video>
        <div x-show="scanning" class="dtk-scan__reticle"></div>
        <div x-show="scanning" class="dtk-scan__hint" x-text="hint"></div>
        <div x-show="!scanning" class="dtk-scan__offhint">{{ __('Turn the camera on to scan, or type the code below.') }}</div>
    </div>

    {{-- Switch de cámara: enciende/apaga getUserMedia y libera los tracks al apagar. --}}
    <div class="dtk-scan__switchrow">
        <label class="dtk-scan__switch">
            <input type="checkbox" :checked="cameraOn" @change="toggleCamera($event.target.checked)" />
            <span class="dtk-scan__track" aria-hidden="true"></span>
            <span class="dtk-scan__sr">{{ __('Camera') }}</span>
        </label>
        <span class="dtk-scan__switchlabel">{{ __('Camera') }}</span>
    </div>

    {{-- Respaldo: código a mano (si no hay cámara o BarcodeDetector) --}}
    <form class="dtk-scan__manual" @submit.prevent="manual()">
        <label for="dtk-scan-code" class="dtk-scan__sr">{{ __('Label code') }}</label>
        <input id="dtk-scan-code" type="text" x-model="code" :placeholder="'{{ __('Or type / paste the label code') }}'" autocomplete="off" />
        <button type="submit" class="dtk-scan__btn">{{ __('Resolve') }}</button>
    </form>

    <p x-show="error" x-cloak class="dtk-scan__err" x-text="error"></p>

    {{-- Resultado --}}
    <template x-if="result">
        <div class="dtk-scan__card">
            <template x-if="result.resolved">
                <div>
                    <div class="dtk-scan__ok" style="font-weight:700">✓ {{ __('Label resolved') }}</div>
                    <dl class="dtk-scan__kv">
                        <dt>{{ __('Product') }}</dt><dd x-text="result.product_name || result.sku || '—'"></dd>
                        <dt>SKU</dt><dd x-text="result.sku || '—'"></dd>
                        <dt>{{ __('Label code') }}</dt><dd style="word-break:break-all" x-text="result.label_id"></dd>
                    </dl>
                </div>
            </template>
            <template x-if="!result.resolved">
                <div class="dtk-scan__bad" style="font-weight:700">
                    ✗ {{ __('Label not recognized') }}
                    <span style="display:block;font-weight:400;color:#a1a1aa;margin-top:.3rem;word-break:break-all" x-text="result.label_id || code"></span>
                </div>
            </template>
        </div>
    </template>
</div>
