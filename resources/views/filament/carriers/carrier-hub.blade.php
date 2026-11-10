{{--
    Hub 360 de transportista (carrier) — cockpit de detalle. Agrega ficha (estado, calificación),
    liquidaciones (pendientes/monto) y tarifas por corredor. Datos reales y tolerantes vía
    App\Support\Cockpit\CarrierHub ($this->hub). Las tarifas y liquidaciones se gestionan desde la sección
    «Gestión del transportista». Capa `.distrack-cockpit`, español directo. NUNCA UUID visible.
--}}
@php
    $h = $this->hub;
    $c = $h['carrier'];
    $s = $h['settlements'];
    $tr = $h['tariffs'];

    $sym = static fn (string $cur): string => match (strtoupper($cur)) { 'BOB' => 'Bs', 'USD' => '$', default => $cur };
    $money = static fn (int $cents, string $cur): string => $sym($cur).' '.number_format($cents / 100, 2);

    $statusMap = [
        'active' => ['Activo', 'positive'],
        'suspended' => ['Suspendido', 'danger'],
    ];
    [$statusLabel, $statusTone] = $statusMap[strtolower((string) ($c['status'] ?? ''))] ?? [ucfirst(strtolower((string) ($c['status'] ?? '—'))), ''];

    $setStatus = static fn (string $st): array => match (strtolower($st)) {
        'pending' => ['Pendiente', ''],
        'approved' => ['Aprobada', 'agent'],
        'disputed' => ['En disputa', 'danger'],
        'paid', 'settled' => ['Pagada', 'positive'],
        'cancelled' => ['Anulada', ''],
        default => [ucfirst(strtolower($st)), ''],
    };
    $stars = static fn (float $r): string => str_repeat('★', (int) round($r)).str_repeat('☆', max(0, 5 - (int) round($r)));
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        @if($c)
            {{-- ===== HERO ===== --}}
            <div class="ck-hub__head">
                <div class="ck-hub__id">
                    <div style="min-width:0">
                        <div class="ck-hub__title">{{ $c['name'] }}</div>
                        <div class="ck-hub__sub">
                            Transportista{{ $c['tax_id'] ? ' · NIT '.$c['tax_id'] : '' }}
                        </div>
                        <div class="ck-hub__pills">
                            <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                            @if($c['rating'] !== null)
                                <span class="ck-pill">{{ $stars((float) $c['rating']) }} {{ number_format((float) $c['rating'], 2) }} · {{ $c['rating_count'] }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="ck-hub__aside">
                    @if($s['open'] > 0)
                        <span class="ck-pill ck-pill--warning"><span class="ck-pill__dot"></span>{{ $s['open'] }} liquidaciones pendientes</span>
                    @else
                        <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>Sin pendientes</span>
                    @endif
                </div>
            </div>

            {{-- ===== KPI STRIP ===== --}}
            <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
                <div class="ck-stat {{ $s['open'] > 0 ? 'ck-stat--warning' : '' }}">
                    <div class="ck-stat__label">Liquidaciones pendientes</div>
                    <div class="ck-stat__value">{{ number_format($s['open']) }}</div>
                    <div class="ck-stat__hint">{{ number_format($s['total']) }} en total</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Monto pendiente</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $money($s['open_amount_cents'], $s['currency']) }}</div>
                </div>
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Pagadas</div>
                    <div class="ck-stat__value">{{ number_format($s['paid']) }}</div>
                </div>
                <div class="ck-stat">
                    <div class="ck-stat__label">Tarifas</div>
                    <div class="ck-stat__value">{{ number_format($tr['total']) }}</div>
                    <div class="ck-stat__hint">por corredor</div>
                </div>
            </div>

            {{-- ===== GESTIÓN DEL TRANSPORTISTA ===== --}}
            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del transportista</div>
                    <div class="ck-hub__pills" style="margin-top:.7rem">
                        @foreach($this->managementLinks() as $m)
                            <a href="{{ $m['url'] }}" wire:navigate class="ck-pill ck-pill--agent">{{ $m['label'] }} →</a>
                        @endforeach
                    </div>
                    <div class="ck-hub__sub" style="margin-top:.7rem">Las tarifas y liquidaciones dependen del transportista: se gestionan desde su ficha, no desde el menú.</div>
                </div>
            @endif

            {{-- ===== LIQUIDACIONES + TARIFAS ===== --}}
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Liquidaciones recientes</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($s['recent'] as $row)
                            @php [$sl, $st] = $setStatus($row['status']); @endphp
                            <div class="ck-event">
                                <span class="ck-event__ic {{ $st === 'danger' ? 'ck-event__ic--red' : '' }}"></span>
                                <span class="ck-event__tx">
                                    {{ $row['period'] }}
                                    <small>{{ $row['corridor'] }}</small>
                                </span>
                                <span class="ck-event__when">{{ $money($row['amount_cents'], $row['currency']) }}</span>
                                <span class="ck-pill {{ $st ? 'ck-pill--'.$st : '' }}">{{ $sl }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin liquidaciones</div><div class="ck-empty__hint">Este transportista no tiene liquidaciones registradas.</div></div>
                        @endforelse
                    </div>
                </div>
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Tarifas por corredor</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($tr['recent'] as $row)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ $row['corridor'] }}
                                    @if($row['vehicle_class'])<small>Clase: {{ $row['vehicle_class'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">{{ $money($row['price_cents'], $row['currency']) }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin tarifas</div><div class="ck-empty__hint">Este transportista no tiene tarifas cargadas.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        @else
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Transportista no disponible</div>
                <div class="ck-empty__hint">No se pudo cargar la ficha de este transportista.</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
