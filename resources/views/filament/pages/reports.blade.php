{{--
    Reportes de operaciones (Fase 5) — descarga CSV de la analítica y vista previa del digest diario.
    Capa `.distrack-cockpit`, español directo, clases ck-* existentes. Datos reales vía ReportBuilder
    ($this->summary). NUNCA UUID visible. SIN CSS nuevo, CERO CDN.
--}}
@php
    $s = $this->summary;
    $barMax = max(1, ...array_map(static fn ($r) => (int) $r['count'], $s['status'] ?: [['count' => 0]]));
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Reportes de operaciones</div>
                    <div class="ck-hub__sub">Resumen de los últimos {{ $s['window_days'] }} días · exportable a CSV · digest diario por correo</div>
                </div>
            </div>
            <div class="ck-hub__aside">
                <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>Digest 07:30</span>
            </div>
        </div>

        {{-- ===== RESUMEN (lo que va en el digest) ===== --}}
        <div class="ck-grid ck-grid--4 ck-section">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Recibidos</div>
                <div class="ck-stat__value">{{ number_format($s['received']) }}</div>
                <div class="ck-stat__hint">últimos {{ $s['window_days'] }} días</div>
            </div>
            <div class="ck-stat ck-stat--positive">
                <div class="ck-stat__label">Entregados</div>
                <div class="ck-stat__value">{{ number_format($s['delivered']) }}</div>
            </div>
            <div class="ck-stat {{ $s['effectiveness_pct'] !== null ? ($s['effectiveness_pct'] >= 90 ? 'ck-stat--positive' : ($s['effectiveness_pct'] >= 75 ? 'ck-stat--warning' : 'ck-stat--danger')) : '' }}">
                <div class="ck-stat__label">Efectividad</div>
                <div class="ck-stat__value">{{ $s['effectiveness_pct'] !== null ? $s['effectiveness_pct'].'%' : '—' }}</div>
                <div class="ck-stat__hint">{{ number_format($s['failed']) }} fallidas</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Nodo con más demanda</div>
                <div class="ck-stat__value" style="font-size:1.2rem">{{ $s['top_destination']['label'] ?? '—' }}</div>
                <div class="ck-stat__hint">{{ $s['top_destination'] ? number_format($s['top_destination']['count']).' envíos' : 'sin datos' }}</div>
            </div>
        </div>

        <div class="ck-grid ck-grid--2 ck-section">
            {{-- Distribución por estado --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Distribución por estado</div>
                <div style="margin-top:.8rem;display:flex;flex-direction:column;gap:.7rem">
                    @foreach($s['status'] as $row)
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem">
                                <span>{{ $row['label'] }}</span><span class="ck-mono">{{ number_format($row['count']) }}</span>
                            </div>
                            <div style="height:8px;border-radius:6px;background:var(--ck-line,#2a2a2a);overflow:hidden">
                                <div style="height:100%;width:{{ (int) round($row['count'] / $barMax * 100) }}%;background:var(--ck-red,#e30613);opacity:.85"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Descargas CSV --}}
            <div class="ck-card">
                <div class="ck-card__eyebrow">Exportar a CSV</div>
                <div class="ck-hub__sub" style="margin-top:.3rem">Descargá cada corte para tu planilla o tu tablero externo.</div>
                <div style="margin-top:.9rem;display:flex;flex-direction:column;gap:.55rem">
                    @foreach($this->datasets as $key => $label)
                        <button type="button" wire:click="exportCsv('{{ $key }}')" wire:loading.attr="disabled"
                                style="display:flex;align-items:center;justify-content:space-between;gap:.8rem;width:100%;text-align:left;
                                       background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);
                                       border-radius:10px;padding:.65rem .9rem;font:inherit;cursor:pointer">
                            <span>{{ $label }}</span>
                            <span class="ck-pill ck-pill--agent">Descargar CSV ↓</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">Digest diario por correo</div>
            <div class="ck-hub__sub" style="margin-top:.4rem">
                Cada día a las 07:30 se envía a administradores y supervisores un resumen con estos números
                (entregas, efectividad y excepciones). Se genera con <span class="ck-mono">ops:digest</span>.
            </div>
        </div>
    </div>
</x-filament-panels::page>
