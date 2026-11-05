{{-- Command palette global (⌘K / Ctrl+K) de DisTrack OS. 100% cliente: Alpine controla abrir/cerrar y la
     navegación con teclado; los resultados llegan por fetch mismo-origen a /cockpit/search (JSON), sin
     round-trip de Livewire por tecla (más rápido, sin CSRF/419). Se monta una vez por render hook. --}}
<div
    x-data="dtkPalette()"
    x-on:keydown.window.meta.k.prevent="openP()"
    x-on:keydown.window.ctrl.k.prevent="openP()"
    x-on:dtk-cmd-open.window="openP()"
    x-on:keydown.escape.window="open = false"
>
    <div class="dtk-cmd-overlay" x-show="open" x-transition.opacity.duration.150ms style="display:none" @click.self="open = false">
        <div class="dtk-cmd" @click.stop>
            <div class="dtk-cmd__inp">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                <input
                    x-ref="input" type="text" autocomplete="off" spellcheck="false"
                    x-model="q"
                    placeholder="Buscar placa, nº de pedido, cliente, chofer, nodo…"
                    @keydown.down.prevent="active = Math.min(active + 1, Math.max(flat.length - 1, 0))"
                    @keydown.up.prevent="active = Math.max(active - 1, 0)"
                    @keydown.enter.prevent="go()"
                >
                <span class="ck-kbd">esc</span>
            </div>

            <div class="dtk-cmd__results">
                <template x-if="loading">
                    <div class="dtk-cmd__empty">Buscando…</div>
                </template>

                <template x-if="!loading && q.trim().length >= 2 && flat.length === 0">
                    <div class="dtk-cmd__empty">Sin resultados para «<span x-text="q.trim()"></span>».</div>
                </template>

                <template x-if="!loading && q.trim().length < 2">
                    <div class="dtk-cmd__empty">Buscá por placa, nº de pedido, cliente, chofer, nodo o transportista.</div>
                </template>

                <template x-for="g in groups" :key="g.group">
                    <div>
                        <div class="dtk-cmd__group" x-text="g.group"></div>
                        <template x-for="it in g.items" :key="it._i">
                            <a
                                :href="it.url || '#'"
                                class="dtk-cmd__item"
                                :class="{ 'is-active': active === it._i }"
                                @mouseenter="active = it._i"
                                @click="open = false"
                            >
                                <span class="ki" x-text="it.icon"></span>
                                <span class="tx"><b x-text="it.title"></b><small x-text="it.subtitle"></small></span>
                                <span class="go">↵</span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>

            <div class="dtk-cmd__foot">
                <span><span class="ck-kbd">↑</span><span class="ck-kbd">↓</span> navegar</span>
                <span><span class="ck-kbd">↵</span> abrir</span>
                <span><span class="ck-kbd">⌘ K</span> para abrir</span>
            </div>
        </div>
    </div>
</div>

<script>
    // Estado del command palette: búsqueda por fetch con debounce, descarte de respuestas viejas por
    // secuencia, y un índice plano (it._i) para navegar con teclado a través de los grupos.
    window.dtkPalette = window.dtkPalette || function () {
        return {
            open: false,
            q: '',
            groups: [],
            flat: [],
            active: 0,
            loading: false,
            _timer: null,
            _seq: 0,

            init() {
                this.$watch('q', () => this.schedule());
            },

            openP() {
                this.open = true;
                this.$nextTick(() => { if (this.$refs.input) this.$refs.input.focus(); });
            },

            schedule() {
                clearTimeout(this._timer);
                const q = this.q.trim();
                if (q.length < 2) {
                    this.groups = [];
                    this.flat = [];
                    this.loading = false;
                    return;
                }
                this.loading = true;
                this._timer = setTimeout(() => this.fetchNow(q), 220);
            },

            async fetchNow(q) {
                const seq = ++this._seq;
                try {
                    const res = await fetch('/cockpit/search?q=' + encodeURIComponent(q), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (seq !== this._seq) return; // llegó una respuesta más nueva
                    if (!res.ok) { this.groups = []; this.flat = []; this.loading = false; return; }
                    const data = await res.json();
                    if (seq !== this._seq) return;
                    const flat = [];
                    (data.groups || []).forEach(g => (g.items || []).forEach(it => { it._i = flat.length; flat.push(it); }));
                    this.groups = data.groups || [];
                    this.flat = flat;
                    this.active = 0;
                    this.loading = false;
                } catch (e) {
                    if (seq === this._seq) { this.groups = []; this.flat = []; this.loading = false; }
                }
            },

            go() {
                const it = this.flat[this.active];
                if (it && it.url) { window.location.href = it.url; }
            },
        };
    };
</script>
