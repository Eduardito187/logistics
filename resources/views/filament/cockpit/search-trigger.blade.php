{{-- Disparador del command palette en el topbar: abre el palette (evento Alpine) o con ⌘K. --}}
<div x-data>
    <button type="button" class="dtk-cmd-trigger" title="Buscar · ⌘K" @click="$dispatch('dtk-cmd-open')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <span>Buscar…</span>
        <span class="ck-kbd">⌘ K</span>
    </button>
</div>
