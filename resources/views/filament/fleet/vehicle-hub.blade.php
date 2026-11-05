{{--
    Hub 360 de vehículo — cockpit de detalle (DisTrack OS, Fase 2). Agrega en una pantalla: identidad +
    estado, telemetría en vivo, chofer/ruta de hoy, costos y utilización (30d), documentos con vencimiento,
    y actividad (alertas de mal uso + incidentes). Datos reales y tolerantes vía App\Support\Cockpit\VehicleHub
    (expuestos por $this->hub). Capa de diseño `.distrack-cockpit` (theme.css). Español para el cliente;
    NUNCA se muestra un UUID (la referencia es placa/nombre/tipo). Conserva el chrome de página Filament
    (cabecera + acciones Editar/Documentos).
--}}
<x-filament-panels::page>
    @php
        /** @var \Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel $v */
        $v = $this->record;
        $h = $this->hub;
        $live = $h['live'];
        $asg = $h['assignment'];
        $cost = $h['cost'];
        $util = $h['utilization'];
        $comp = $h['compliance'];
        $docs = $h['documents'];
        $alerts = $h['alerts'];
        $incidents = $h['incidents'];
        $km = $h['km_series'];

        $ph = \Logistics\Shared\Presentation\Filament\ImageFallback::placeholder('box');
        $currency = $cost['currency'] ?? 'BOB';
        $sym = static fn (string $c): string => match (strtoupper($c)) { 'BOB' => 'Bs', 'USD' => '$', default => $c };
        $money = static function (?int $cents, string $c = 'BOB') use ($sym): string {
            return $cents === null ? '—' : $sym($c).' '.number_format($cents / 100, 2);
        };
        $m3 = static fn ($x): string => rtrim(rtrim(number_format((float) $x, 3), '0'), '.');

        $statusMap = [
            'available' => ['Disponible', 'positive'],
            'in_service' => ['En servicio', 'agent'],
            'maintenance' => ['En taller', 'warning'],
            'retired' => ['Retirado', 'danger'],
        ];
        [$statusLabel, $statusTone] = $statusMap[$v->status] ?? [ucfirst(str_replace('_', ' ', (string) $v->status)), ''];

        $alertLabels = [
            'after_hours_movement' => 'Movimiento fuera de horario',
            'excessive_idle' => 'Ralentí excesivo',
            'geofence_violation' => 'Violación de geocerca',
            'unauthorized_use' => 'Uso no autorizado',
            'speeding' => 'Exceso de velocidad',
        ];
        $sevIc = static fn (string $s): string => match (strtolower($s)) {
            'critical', 'high' => 'ck-event__ic--red',
            'medium', 'warning' => 'ck-event__ic--amber',
            default => '',
        };
        $docState = [
            'expired' => ['Vencido', 'danger', 'ck-event__ic--red'],
            'due_soon' => ['Por vencer', 'warning', 'ck-event__ic--amber'],
            'upcoming' => ['Próximo', '', ''],
            'ok' => ['Vigente', 'positive', ''],
        ];

        $compTotal = $comp['expired'] + $comp['due_soon'];
        // Km recorridos: se prefiere la telemetría (misma fuente que el gráfico y el costo-por-km); la
        // utilización por salidas es un dato distinto (0 si no se registraron salidas) y va como detalle.
        $kmTotal = $cost ? $cost['kilometers'] : ($util['km'] ?? null);
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        {{-- ===== HERO ===== --}}
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <img class="ck-hub__photo" src="{{ $this->photoUrl($v->photo_front) ?? $ph }}" alt=""
                     onerror="this.onerror=null;this.src='{{ $ph }}'">
                <div style="min-width:0">
                    <div class="ck-hub__title">{{ $v->plate }}</div>
                    <div class="ck-hub__sub">
                        {{ trim(($v->make ?? '').' '.($v->model ?? '')) ?: 'Vehículo' }}@if($v->model_year) · {{ $v->model_year }}@endif
                        @if($v->vin) · <span class="ck-mono">VIN {{ $v->vin }}</span>@endif
                    </div>
                    <div class="ck-hub__pills">
                        <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                        @if($v->homeBaseNode?->name)
                            <span class="ck-pill">Base: {{ $v->homeBaseNode->name }}</span>
                        @endif
                        <span class="ck-pill">{{ number_format((float) $v->capacity_kg) }} kg · {{ $m3($v->capacity_m3) }} m³</span>
                    </div>
                </div>
            </div>

            <div class="ck-hub__aside">
                @if($live)
                    <span class="ck-pill {{ $live['moving'] ? 'ck-pill--positive' : '' }}">
                        <span class="ck-pill__dot"></span>
                        {{ ($live['moving'] ? 'En movimiento' : 'Detenido').($live['speed_kph'] !== null ? ' · '.(int) round($live['speed_kph']).' km/h' : '') }}
                    </span>
                    @if($live['recorded_at'])
                        <span class="ck-hub__sub">Última señal: {{ \Illuminate\Support\Carbon::parse($live['recorded_at'])->isoFormat('DD MMM HH:mm') }}</span>
                    @endif
                @else
                    <span class="ck-pill">Sin telemetría en vivo</span>
                @endif
                @if($asg['driver_name'])
                    <span class="ck-hub__sub">Chofer hoy: <b style="color:var(--ck-ink)">{{ $asg['driver_name'] }}</b></span>
                @endif
            </div>
        </div>

        {{-- ===== KPI STRIP ===== --}}
        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Costo por km · 30d</div>
                <div class="ck-stat__value">{{ $cost && $cost['cost_per_km_cents'] !== null ? $money($cost['cost_per_km_cents'], $currency) : '—' }}</div>
                <div class="ck-stat__hint">{{ $cost ? $money($cost['total_cents'], $currency).' total' : 'Sin datos de costo' }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Km recorridos · 30d</div>
                <div class="ck-stat__value">{{ $kmTotal !== null ? number_format($kmTotal) : '—' }}</div>
                <div class="ck-stat__hint">{{ $util ? $util['departures'].' salidas · '.number_format($util['hours'], 0).' h' : 'Telemetría' }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Odómetro</div>
                <div class="ck-stat__value">{{ $util && $util['last_odometer_km'] !== null ? number_format($util['last_odometer_km']) : '—' }}</div>
                <div class="ck-stat__hint">{{ $util && $util['last_odometer_read_at'] ? 'Lectura '.$util['last_odometer_read_at'] : 'km · sin lectura' }}</div>
            </div>
            <div class="ck-stat {{ $comp['expired'] > 0 ? 'ck-stat--danger' : ($comp['due_soon'] > 0 ? 'ck-stat--warning' : 'ck-stat--positive') }}">
                <div class="ck-stat__label">Documentos</div>
                <div class="ck-stat__value">{{ $compTotal > 0 ? $compTotal : 'OK' }}</div>
                <div class="ck-stat__hint">{{ $comp['expired'] > 0 ? $comp['expired'].' vencidos' : ($comp['due_soon'] > 0 ? $comp['due_soon'].' por vencer · 7d' : 'Al día') }}</div>
            </div>
        </div>

        {{-- ===== TABS ===== --}}
        <div class="ck-tabs">
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'tech' }" @click="t = 'tech'">Ficha técnica</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'cost' }" @click="t = 'cost'">Costos</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'docs' }" @click="t = 'docs'">Documentos<span class="ck-tab__n">{{ count($docs) }}</span></button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'activity' }" @click="t = 'activity'">Actividad<span class="ck-tab__n">{{ count($alerts) + count($incidents) }}</span></button>
        </div>

        {{-- ===== PANEL · RESUMEN ===== --}}
        <div x-show="t === 'summary'">
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Ruta de hoy</div>
                    @if($asg['route'])
                        <div class="ck-card__title">{{ ucfirst(strtolower((string) $asg['route']['status'])) }} · {{ $asg['route']['completed'] }}/{{ $asg['route']['total'] }} paradas</div>
                        @if(count($asg['route']['stops']))
                            <div class="ck-timeline" style="margin-top:1.1rem">
                                @foreach(array_slice($asg['route']['stops'], 0, 8) as $stop)
                                    <div class="ck-stop {{ $stop['state'] === 'done' ? 'ck-stop--done' : ($stop['state'] === 'warn' ? 'ck-stop--current' : '') }}">
                                        <div class="ck-stop__dot"></div>
                                        <div class="ck-stop__label">{{ $stop['label'] }}</div>
                                        <div class="ck-stop__eta">{{ $stop['eta'] ?? '—' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="ck-empty">
                            <div class="ck-empty__title">Sin ruta asignada hoy</div>
                            <div class="ck-empty__hint">Este vehículo no tiene una ruta abierta.</div>
                        </div>
                    @endif
                </div>

                <div class="ck-card">
                    <div class="ck-card__eyebrow">Costo · últimos 30 días</div>
                    @if($cost && $cost['total_cents'] > 0)
                        @php $tot = $cost['total_cents']; @endphp
                        <div class="ck-card__title">{{ $money($tot, $currency) }}</div>
                        <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:1.1rem">
                            @foreach([['Combustible', 'fuel_cents', 'amber'], ['Mantenimiento', 'maintenance_cents', 'red'], ['Otros gastos', 'expenses_cents', 'green']] as $row)
                                <div class="ck-bar ck-bar--{{ $row[2] }}">
                                    <span class="ck-bar__label">{{ $row[0] }}</span>
                                    <span class="ck-bar__count">{{ $money($cost[$row[1]], $currency) }}</span>
                                    <span class="ck-bar__track"><span class="ck-bar__fill ck-bar__fill--{{ $row[2] }}" style="width:{{ $tot > 0 ? round($cost[$row[1]] / $tot * 100) : 0 }}%"></span></span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="ck-empty">
                            <div class="ck-empty__title">Sin costos en el periodo</div>
                            <div class="ck-empty__hint">No hay combustible, mantenimiento ni gastos cargados.</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===== PANEL · FICHA TÉCNICA ===== --}}
        <div x-show="t === 'tech'" x-cloak>
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Ficha técnica</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>Marca</dt><dd>{{ $v->make ?: '—' }}</dd>
                        <dt>Modelo</dt><dd>{{ $v->model ?: '—' }}</dd>
                        <dt>Año</dt><dd>{{ $v->model_year ?: '—' }}</dd>
                        <dt>VIN</dt><dd class="ck-mono">{{ $v->vin ?: '—' }}</dd>
                        <dt>Nº de motor</dt><dd class="ck-mono">{{ $v->engine_number ?: '—' }}</dd>
                        <dt>Color</dt><dd>{{ $v->color ?: '—' }}</dd>
                        <dt>Capacidad</dt><dd>{{ number_format((float) $v->capacity_kg) }} kg</dd>
                        <dt>Volumen</dt><dd>{{ $m3($v->capacity_m3) }} m³</dd>
                    </dl>
                </div>
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Fotos</div>
                    <div class="ck-photos" style="margin-top:.9rem">
                        @foreach([['photo_front', 'Frente'], ['photo_back', 'Atrás'], ['photo_left', 'Izquierda'], ['photo_right', 'Derecha']] as $row)
                            <figure>
                                <img src="{{ $this->photoUrl($v->{$row[0]}) ?? $ph }}" alt="{{ $row[1] }}"
                                     onerror="this.onerror=null;this.src='{{ $ph }}'">
                                <figcaption>{{ $row[1] }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== PANEL · COSTOS ===== --}}
        <div x-show="t === 'cost'" x-cloak>
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Km por día · últimos 14 días</div>
                <x-cockpit.chart
                    type="bars"
                    :series="$km['values']"
                    :labels="$km['labels']"
                    tone="agent"
                    :height="150"
                    label="Km/día"
                    :value="$kmTotal !== null ? number_format($kmTotal).' km' : null"
                    unit="30d" />
            </div>
            <div class="ck-grid ck-grid--4 ck-section">
                <div class="ck-stat ck-stat--warning">
                    <div class="ck-stat__label">Combustible · 30d</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $cost ? $money($cost['fuel_cents'], $currency) : '—' }}</div>
                </div>
                <div class="ck-stat ck-stat--danger">
                    <div class="ck-stat__label">Mantenimiento · 30d</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $cost ? $money($cost['maintenance_cents'], $currency) : '—' }}</div>
                </div>
                <div class="ck-stat ck-stat--positive">
                    <div class="ck-stat__label">Otros gastos · 30d</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $cost ? $money($cost['expenses_cents'], $currency) : '—' }}</div>
                </div>
                <div class="ck-stat ck-stat--agent">
                    <div class="ck-stat__label">Costo por km</div>
                    <div class="ck-stat__value" style="font-size:1.3rem">{{ $cost && $cost['cost_per_km_cents'] !== null ? $money($cost['cost_per_km_cents'], $currency) : '—' }}</div>
                </div>
            </div>
        </div>

        {{-- ===== PANEL · DOCUMENTOS ===== --}}
        <div x-show="t === 'docs'" x-cloak>
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Documentos del vehículo</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($docs as $doc)
                        @php [$dLbl, $dTone, $dIc] = $docState[$doc['state']] ?? ['—', '', '']; @endphp
                        <div class="ck-event">
                            <span class="ck-event__ic {{ $dIc }}"></span>
                            <span class="ck-event__tx">
                                {{ $doc['type'] }}
                                @if($doc['reference'])<small>{{ $doc['reference'] }}</small>@endif
                            </span>
                            <span class="ck-pill {{ $dTone ? 'ck-pill--'.$dTone : '' }}">{{ $dLbl }}</span>
                            <span class="ck-event__when">{{ \Illuminate\Support\Carbon::parse($doc['expires_on'])->isoFormat('DD MMM YYYY') }}</span>
                        </div>
                    @empty
                        <div class="ck-empty">
                            <div class="ck-empty__title">Sin documentos registrados</div>
                            <div class="ck-empty__hint">Registrá SOAT, inspección técnica u otros desde el botón «Documentos».</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ===== PANEL · ACTIVIDAD ===== --}}
        <div x-show="t === 'activity'" x-cloak>
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Alertas de mal uso</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($alerts as $a)
                            <div class="ck-event">
                                <span class="ck-event__ic {{ $sevIc($a['severity']) }}"></span>
                                <span class="ck-event__tx">{{ $alertLabels[$a['type']] ?? ucfirst(str_replace('_', ' ', $a['type'])) }}</span>
                                <span class="ck-event__when">{{ $a['occurred_at'] }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin alertas</div><div class="ck-empty__hint">No se registró mal uso reciente.</div></div>
                        @endforelse
                    </div>
                </div>
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Incidentes</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($incidents as $i)
                            <div class="ck-event">
                                <span class="ck-event__ic {{ $sevIc($i['severity']) }}"></span>
                                <span class="ck-event__tx">
                                    {{ ucfirst(str_replace('_', ' ', $i['type'])) }}
                                    @if($i['note'])<small>{{ $i['note'] }}</small>@endif
                                </span>
                                <span class="ck-event__when">{{ $i['occurred_at'] }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin incidentes</div><div class="ck-empty__hint">No hay averías ni incidentes reportados.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

    </div>
</x-filament-panels::page>
