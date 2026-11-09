{{--
    Analítica de operaciones (Fase 4 — "datos que se ven"). Capa `.distrack-cockpit` con los componentes
    x-cockpit.*. Composición: pulso de KPIs + efectividad, tendencias diarias (recibidos/entregados),
    carga por estado y concentración de demanda por nodo. Datos REALES y tolerantes vía los servicios del
    cockpit ($this->pulse / $this->trends / $this->effectiveness / $this->topDestinations). Español directo
    (excepción cockpit); NUNCA se muestra un UUID (los nodos salen por su nombre).
--}}
<x-filament-panels::page>
    @php
        $tiles = collect($this->pulse());
        $tr = $this->trends();
        $eff = $this->effectiveness();
        $dests = $this->topDestinations();
        $statusMax = max(1, max(array_column($tr['status'], 'count')));
        $destMax = count($dests) ? max(1, max(array_column($dests, 'count'))) : 1;
    @endphp

    <div class="distrack-cockpit">

        {{-- ====================== PULSO DE KPIs ====================== --}}
        <div class="ck-pulse">
            @foreach ($tiles as $tile)
                <x-cockpit.stat
                    :label="__($tile['label'] ?? '')"
                    :value="number_format((int) ($tile['value'] ?? 0))"
                    :tone="$tile['tone'] ?? 'neutral'"
                    :delta="$tile['delta'] ?? null"
                    :trend="$tile['trend'] ?? 'flat'"
                    :delta-tone="$tile['delta_tone'] ?? 'neutral'"
                    :hint="$tile['hint'] ?? null"
                />
            @endforeach
        </div>

        {{-- ====================== EFECTIVIDAD + CARGA POR ESTADO ====================== --}}
        <div class="ck-grid ck-grid--2 ck-section">
            <x-cockpit.card eyebrow="Entregas cerradas" title="Efectividad de entrega">
                @if ($eff['closed'] > 0)
                    <div class="ck-chart__head" style="margin-top:.4rem">
                        <span class="ck-chart__big">{{ $eff['pct'] ?? 0 }}<span class="u">%</span></span>
                        <span class="ck-chart__label">{{ number_format($eff['delivered']) }} de {{ number_format($eff['closed']) }} entregadas</span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:1rem">
                        <div class="ck-bar">
                            <span class="ck-bar__label">Entregadas</span>
                            <span class="ck-bar__count">{{ number_format($eff['delivered']) }}</span>
                            <span class="ck-bar__track"><span class="ck-bar__fill ck-bar__fill--green" style="width:{{ max(3, (int) round($eff['delivered'] / max(1, $eff['closed']) * 100)) }}%"></span></span>
                        </div>
                        <div class="ck-bar">
                            <span class="ck-bar__label">Fallidas</span>
                            <span class="ck-bar__count">{{ number_format($eff['failed']) }}</span>
                            <span class="ck-bar__track"><span class="ck-bar__fill ck-bar__fill--red" style="width:{{ $eff['failed'] > 0 ? max(3, (int) round($eff['failed'] / max(1, $eff['closed']) * 100)) : 0 }}%"></span></span>
                        </div>
                    </div>
                @else
                    <div class="ck-empty" style="margin-top:.6rem"><div class="ck-empty__title">Sin entregas cerradas</div><div class="ck-empty__hint">Todavía no hay entregas confirmadas ni fallidas para medir.</div></div>
                @endif
            </x-cockpit.card>

            <x-cockpit.card eyebrow="Carga por estado" title="Envíos en la red">
                <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:.9rem">
                    @foreach ($tr['status'] as $row)
                        @php $fill = in_array($row['tone'], ['red', 'amber', 'green'], true) ? 'ck-bar__fill--'.$row['tone'] : ''; @endphp
                        <div class="ck-bar">
                            <span class="ck-bar__label">{{ $row['label'] }}</span>
                            <span class="ck-bar__count">{{ number_format($row['count']) }}</span>
                            <span class="ck-bar__track"><span class="ck-bar__fill {{ $fill }}" style="width:{{ $row['count'] > 0 ? max(3, (int) round($row['count'] / $statusMax * 100)) : 0 }}%"></span></span>
                        </div>
                    @endforeach
                </div>
            </x-cockpit.card>
        </div>

        {{-- ====================== TENDENCIAS DIARIAS ====================== --}}
        <div class="ck-grid ck-grid--2 ck-section">
            <x-cockpit.card eyebrow="Últimos 14 días" title="Envíos recibidos">
                <div style="margin-top:.6rem">
                    <x-cockpit.chart
                        type="area"
                        :series="$tr['received']['values']"
                        :labels="$tr['received']['labels']"
                        tone="agent"
                        :height="150"
                        label="Recibidos"
                        :value="number_format($tr['totals']['received'])"
                        unit="14d" />
                </div>
            </x-cockpit.card>

            <x-cockpit.card eyebrow="Últimos 14 días" title="Entregas confirmadas">
                <div style="margin-top:.6rem">
                    <x-cockpit.chart
                        type="bars"
                        :series="$tr['delivered']['values']"
                        :labels="$tr['delivered']['labels']"
                        tone="green"
                        :height="150"
                        label="Entregados"
                        :value="number_format($tr['totals']['delivered'])"
                        unit="14d" />
                </div>
            </x-cockpit.card>
        </div>

        {{-- ====================== CONCENTRACIÓN DE DEMANDA ====================== --}}
        <div class="ck-card ck-section">
            <div class="ck-card__eyebrow">Concentración de demanda · nodos destino con más envíos</div>
            <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:.9rem">
                @forelse ($dests as $row)
                    <div class="ck-bar">
                        <span class="ck-bar__label">{{ $row['label'] }}</span>
                        <span class="ck-bar__count">{{ number_format($row['count']) }}</span>
                        <span class="ck-bar__track"><span class="ck-bar__fill" style="width:{{ max(3, (int) round($row['count'] / $destMax * 100)) }}%"></span></span>
                    </div>
                @empty
                    <div class="ck-empty"><div class="ck-empty__title">Sin envíos con nodo destino</div><div class="ck-empty__hint">Los envíos a domicilio no cuentan para este corte.</div></div>
                @endforelse
            </div>
        </div>

    </div>
</x-filament-panels::page>
