<x-filament-panels::page>
    {{-- Escáner de etiquetas en la web: cámara + BarcodeDetector nativo (QR y Code128), sin dependencias
         externas (CERO CDN). Resuelve por /cockpit/label/resolve. Respaldo: tipear/pegar el código único. --}}
    <div
        x-data="dtkLabelScanner()"
        x-init="init()"
        @hook:panels::page.unmounted.window="stop()"
        class="dtk-scan"
    >
        <style>
            .dtk-scan { max-width: 36rem; }
            .dtk-scan__stage { position: relative; aspect-ratio: 4 / 3; width: 100%; overflow: hidden;
                border-radius: 1rem; background: #0a0a0a; border: 1px solid rgba(255,255,255,.1); }
            .dtk-scan__stage video { width: 100%; height: 100%; object-fit: cover; }
            .dtk-scan__reticle { position: absolute; inset: 18% 14%; border: 2px solid rgba(227,6,19,.9);
                border-radius: .75rem; box-shadow: 0 0 0 9999px rgba(0,0,0,.35); pointer-events: none; }
            .dtk-scan__hint { position: absolute; inset-inline: 0; bottom: .6rem; text-align: center;
                font-size: .8rem; color: #e4e4e7; text-shadow: 0 1px 2px #000; }
            .dtk-scan__row { display: flex; gap: .6rem; margin-top: .9rem; flex-wrap: wrap; }
            .dtk-scan__btn { border-radius: .6rem; padding: .55rem 1rem; font-weight: 600; font-size: .85rem;
                cursor: pointer; border: 1px solid transparent; }
            .dtk-scan__btn--primary { background: #e30613; color: #fff; }
            .dtk-scan__btn--ghost { background: transparent; color: #e4e4e7; border-color: rgba(255,255,255,.15); }
            .dtk-scan__manual { display: flex; gap: .5rem; margin-top: 1rem; }
            .dtk-scan__manual input { flex: 1; border-radius: .6rem; border: 1px solid rgba(255,255,255,.15);
                background: #111113; color: #e4e4e7; padding: .55rem .8rem; font: inherit; }
            .dtk-scan__card { margin-top: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,.1);
                background: #111113; padding: 1.1rem 1.25rem; }
            .dtk-scan__ok { color: #34d399; } .dtk-scan__bad { color: #f87171; }
            .dtk-scan__kv { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem; margin-top: .6rem;
                font-size: .9rem; } .dtk-scan__kv dt { color: #a1a1aa; } .dtk-scan__kv dd { margin: 0; font-weight: 600; }
            .dtk-scan__err { margin-top: .8rem; color: #f87171; font-size: .85rem; }
        </style>

        <p style="color:var(--ck-muted,#a1a1aa);margin-bottom:1rem;font-size:.9rem">
            {{ __('Point the camera at the label QR or the Zebra barcode — either one resolves the same package. Works with any of the symbologies.') }}
        </p>

        <div class="dtk-scan__stage">
            <video x-ref="video" playsinline muted></video>
            <div x-show="scanning" class="dtk-scan__reticle"></div>
            <div x-show="scanning" class="dtk-scan__hint" x-text="hint"></div>
        </div>

        <div class="dtk-scan__row">
            <button type="button" class="dtk-scan__btn dtk-scan__btn--primary" x-show="!scanning" @click="start()">
                {{ __('Start camera') }}
            </button>
            <button type="button" class="dtk-scan__btn dtk-scan__btn--ghost" x-show="scanning" @click="stop()">
                {{ __('Stop') }}
            </button>
            <button type="button" class="dtk-scan__btn dtk-scan__btn--ghost" x-show="result" @click="result = null; start()">
                {{ __('Scan another') }}
            </button>
        </div>

        {{-- Respaldo: código a mano (si no hay cámara o BarcodeDetector) --}}
        <form class="dtk-scan__manual" @submit.prevent="manual()">
            <input type="text" x-model="code" :placeholder="'{{ __('Or type / paste the label code') }}'" autocomplete="off" />
            <button type="submit" class="dtk-scan__btn dtk-scan__btn--ghost">{{ __('Resolve') }}</button>
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
                            <dt>{{ __('Unit') }}</dt><dd x-text="(result.unit_index ?? '—') + ' / ' + (result.unit_total ?? '—')"></dd>
                            <dt>{{ __('Piece') }}</dt><dd x-text="(result.piece_index ?? '—') + ' / ' + (result.piece_total ?? '—')"></dd>
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

    <script>
        function dtkLabelScanner() {
            return {
                scanning: false, loading: false, error: '', code: '', result: null,
                stream: null, detector: null,
                hint: @js(__('Scanning…')),
                init() {
                    // Si el navegador no soporta BarcodeDetector, igual sirve el código a mano.
                    if (!('BarcodeDetector' in window)) {
                        this.error = @js(__('This browser cannot scan with the camera; type or paste the label code below.'));
                    }
                },
                async start() {
                    this.error = ''; this.result = null;
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play();
                        this.scanning = true;
                        if ('BarcodeDetector' in window) {
                            this.detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_128'] });
                            this.loop();
                        } else {
                            this.hint = @js(__('Camera on — scan unavailable, use the code field.'));
                        }
                    } catch (e) {
                        this.error = @js(__('Could not open the camera. Check permissions or use the code field.'));
                        this.scanning = false;
                    }
                },
                async loop() {
                    if (!this.scanning || !this.detector) return;
                    try {
                        const codes = await this.detector.detect(this.$refs.video);
                        if (codes && codes.length && codes[0].rawValue) {
                            this.onCode(codes[0].rawValue);
                            return;
                        }
                    } catch (e) { /* frame sin lectura: se reintenta */ }
                    requestAnimationFrame(() => this.loop());
                },
                onCode(raw) { this.code = raw; this.stop(); this.resolve(raw); },
                stop() {
                    this.scanning = false;
                    if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
                },
                manual() { const c = this.code.trim(); if (c) this.resolve(c); },
                async resolve(code) {
                    this.loading = true; this.result = null; this.error = '';
                    try {
                        const r = await fetch('{{ route('cockpit.label.resolve') }}?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' } });
                        this.result = await r.json();
                    } catch (e) { this.error = @js(__('Error resolving the label.')); }
                    this.loading = false;
                },
            };
        }
    </script>
</x-filament-panels::page>
