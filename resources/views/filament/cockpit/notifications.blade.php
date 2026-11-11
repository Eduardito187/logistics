{{-- Centro de NOTIFICACIONES global del header (campana): alertas de telemetría sin reconocer + documentos
     de flota por vencer, unificados y accionables. 100% cliente: Alpine + fetch mismo-origen a
     /cockpit/notifications (JSON). Reemplaza tener "Alertas" como ítem suelto del menú. Solo oficina. --}}
<style>
    [x-cloak] { display: none !important; }
    .dtk-notif { position: relative; display: inline-flex; margin-inline-end: .25rem; }
    .dtk-notif__bell { position: relative; display: inline-flex; align-items: center; justify-content: center;
        width: 2.25rem; height: 2.25rem; border-radius: .625rem; color: rgb(161 161 170); background: transparent;
        border: 1px solid transparent; cursor: pointer; transition: background .15s, color .15s; }
    .dtk-notif__bell:hover { background: rgba(255,255,255,.06); color: rgb(228 228 231); }
    .dtk-notif__badge { position: absolute; top: .15rem; inset-inline-end: .15rem; min-width: 1rem; height: 1rem;
        padding: 0 .2rem; border-radius: 999px; background: #e30613; color: #fff; font-size: .62rem; font-weight: 700;
        line-height: 1rem; text-align: center; box-shadow: 0 0 0 2px var(--dtk-topbar-bg, #0a0a0a); }
    .dtk-notif__panel { position: absolute; top: calc(100% + .5rem); inset-inline-end: 0; width: min(92vw, 23rem);
        max-height: 70vh; overflow-y: auto; z-index: 50; border-radius: .9rem; border: 1px solid rgba(255,255,255,.1);
        background: #111113; box-shadow: 0 16px 48px rgba(0,0,0,.5); color: #e4e4e7; }
    .dtk-notif__head { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1rem;
        font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: rgb(161 161 170);
        border-bottom: 1px solid rgba(255,255,255,.07); }
    .dtk-notif__refresh { background: transparent; border: 0; color: rgb(161 161 170); cursor: pointer; font-size: 1rem; }
    .dtk-notif__empty { padding: 1.25rem 1rem; font-size: .85rem; color: rgb(161 161 170); text-align: center; }
    .dtk-notif__list { list-style: none; margin: 0; padding: 0; }
    .dtk-notif__item { display: flex; align-items: flex-start; gap: .6rem; padding: .7rem 1rem;
        border-bottom: 1px solid rgba(255,255,255,.05); }
    .dtk-notif__dot { margin-top: .4rem; width: .55rem; height: .55rem; border-radius: 999px; flex: none; }
    .dtk-notif__dot--danger { background: #ef4444; }
    .dtk-notif__dot--warning { background: #f59e0b; }
    .dtk-notif__dot--info { background: #38bdf8; }
    .dtk-notif__body { min-width: 0; flex: 1; display: block; text-decoration: none; color: inherit; }
    .dtk-notif__title { display: block; font-size: .85rem; font-weight: 600; color: #f4f4f5;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dtk-notif__sub { display: block; font-size: .75rem; color: rgb(161 161 170); margin-top: .1rem; }
    .dtk-notif__ack { flex: none; align-self: center; border: 1px solid rgba(255,255,255,.12); background: transparent;
        color: #e4e4e7; font-size: .7rem; font-weight: 600; padding: .3rem .55rem; border-radius: .45rem; cursor: pointer; }
    .dtk-notif__ack:hover { background: rgba(255,255,255,.08); }
</style>
<div
    x-data="dtkNotifications()"
    class="dtk-notif"
    @keydown.escape.window="open = false"
>
    <button type="button" class="dtk-notif__bell" @click="toggle()" :aria-expanded="open" aria-label="Notificaciones">
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
        <span x-show="count > 0" x-cloak class="dtk-notif__badge" x-text="count > 99 ? '99+' : count"></span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.120ms class="dtk-notif__panel" role="menu" tabindex="-1" @click.outside="open = false" @keydown.escape="open = false">
        <div class="dtk-notif__head">
            <span>Notificaciones</span>
            <button type="button" class="dtk-notif__refresh" @click="load()" :disabled="loading">↻</button>
        </div>

        <template x-if="!loading && items.length === 0">
            <div class="dtk-notif__empty">Todo en orden — sin notificaciones.</div>
        </template>

        <ul class="dtk-notif__list">
            <template x-for="(n, i) in items" :key="n.id + ':' + i">
                <li class="dtk-notif__item">
                    <span class="dtk-notif__dot" :class="'dtk-notif__dot--' + n.tone"></span>
                    <a :href="n.url" class="dtk-notif__body">
                        <span class="dtk-notif__title" x-text="n.title"></span>
                        <span class="dtk-notif__sub" x-text="n.subtitle"></span>
                    </a>
                    <button
                        type="button" x-show="n.ackable" class="dtk-notif__ack"
                        @click.prevent="ack(n.id)"
                    >Reconocer</button>
                </li>
            </template>
        </ul>
    </div>
</div>

<script>
    function dtkNotifications() {
        return {
            open: false,
            loading: false,
            count: 0,
            items: [],
            timer: null,
            // Alpine llama a init() solo (por eso NO hay x-init="init()": duplicaba carga e intervalo).
            init() {
                if (this.timer) clearInterval(this.timer);
                this.load();
                // Refresco periódico (cada 60 s) para mantener la campana al día sin recargar. Con la
                // pestaña oculta no se pide nada. El intervalo se libera en destroy(): en modo SPA el
                // topbar se reemplaza en cada navegación y, sin esto, se acumulaba un intervalo por página.
                this.timer = setInterval(() => { if (!document.hidden) this.load(); }, 60000);
            },
            destroy() {
                // Alpine lo invoca al quitar el componente del DOM (swap de página SPA).
                if (this.timer) clearInterval(this.timer);
                this.timer = null;
            },
            toggle() {
                this.open = !this.open;
                if (this.open) this.load();
            },
            async load() {
                this.loading = true;
                try {
                    const r = await fetch('{{ route('cockpit.notifications') }}', { headers: { Accept: 'application/json' } });
                    if (r.ok) {
                        const data = await r.json();
                        this.items = Array.isArray(data.items) ? data.items : [];
                        this.count = data.count || this.items.length;
                    }
                } catch (e) { /* red caída: se reintenta en el próximo ciclo */ }
                this.loading = false;
            },
            csrf() {
                const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                return m ? decodeURIComponent(m[1]) : '';
            },
            async ack(id) {
                try {
                    const r = await fetch('{{ url('/cockpit/notifications/ack') }}/' + encodeURIComponent(id), {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': this.csrf() },
                    });
                    if (r.ok) this.load();
                } catch (e) { /* ignora: se puede reintentar */ }
            },
        };
    }
</script>
