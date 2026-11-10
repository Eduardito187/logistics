{{--
    Hub 360 de chofer — cockpit de detalle (DisTrack OS, Fase 2). Agrega: identidad + estado, desempeño
    operativo 30d (entregas/efectividad/días/vehículos), tripulación de hoy, datos personales + referencias,
    licencia y documentos con vencimiento, y actividad (entregas/día + alertas). Datos reales y tolerantes
    vía App\Support\Cockpit\DriverHub ($this->hub). Capa `.distrack-cockpit`. Español; NUNCA se muestra un
    UUID. Conserva el chrome de página Filament (cabecera + acciones Editar/Documentos).
--}}
<x-filament-panels::page>
    @php
        /** @var \Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel $d */
        $d = $this->record;
        $h = $this->hub;
        $hist = $h['history'];
        $crew = $h['crew'];
        $comp = $h['compliance'];
        $docs = $h['documents'];
        $alerts = $h['alerts'];

        $ph = \Logistics\Shared\Presentation\Filament\ImageFallback::placeholder('avatar');

        $age = null;
        if ($d->birth_date) {
            try { $age = \Illuminate\Support\Carbon::parse($d->birth_date)->age; } catch (\Throwable) { $age = null; }
        }

        // Estado de la licencia por su fecha de vencimiento (días absolutos vía DateTimeImmutable::diff,
        // igual que el resto del cockpit; se evita diffInDays de Carbon 3, que ahora viene con signo).
        $licState = 'ok';
        if ($d->license_expiry) {
            try {
                $exp = new \DateTimeImmutable(\Illuminate\Support\Carbon::parse($d->license_expiry)->toDateString());
                $today = new \DateTimeImmutable('today');
                if ($exp < $today) {
                    $licState = 'expired';
                } else {
                    $dl = (int) $today->diff($exp)->format('%a');
                    $licState = $dl <= 7 ? 'due_soon' : ($dl <= 30 ? 'upcoming' : 'ok');
                }
            } catch (\Throwable) { $licState = 'ok'; }
        }

        $sevIc = static fn (string $s): string => match (strtolower($s)) {
            'critical', 'high' => 'ck-event__ic--red',
            'medium', 'warning' => 'ck-event__ic--amber',
            default => '',
        };
        $alertLabels = [
            'after_hours_movement' => 'Movimiento fuera de horario',
            'excessive_idle' => 'Ralentí excesivo',
            'geofence_violation' => 'Violación de geocerca',
            'unauthorized_use' => 'Uso no autorizado',
            'speeding' => 'Exceso de velocidad',
        ];
        $docState = [
            'expired' => ['Vencido', 'danger', 'ck-event__ic--red'],
            'due_soon' => ['Por vencer', 'warning', 'ck-event__ic--amber'],
            'upcoming' => ['Próximo', '', ''],
            'ok' => ['Vigente', 'positive', ''],
        ];
        [$licLbl, $licTone] = $docState[$licState] ?? ['Vigente', 'positive', ''];

        $compTotal = $comp['expired'] + $comp['due_soon'];
        $statusLabel = $d->active ? 'Activo' : 'Inactivo';
        $statusTone = $d->active ? 'positive' : '';
        $references = is_array($d->references) ? $d->references : [];
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        {{-- ===== HERO ===== --}}
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <img class="ck-hub__photo" src="{{ $this->photoUrl($d->photo_path) ?? $ph }}" alt=""
                     onerror="this.onerror=null;this.src='{{ $ph }}'">
                <div style="min-width:0">
                    <div class="ck-hub__title">{{ $d->name }}</div>
                    <div class="ck-hub__sub">
                        Lic. {{ $d->license_number }}{{ $d->license_category ? ' · Cat. '.$d->license_category : '' }}{{ $age !== null ? ' · '.$age.' años' : '' }}
                    </div>
                    <div class="ck-hub__pills">
                        <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                        <span class="ck-pill {{ $licTone ? 'ck-pill--'.$licTone : '' }}">Licencia: {{ $licLbl }}</span>
                        @if($d->national_id)
                            <span class="ck-pill">CI {{ $d->national_id }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="ck-hub__aside">
                @if($crew && $crew['vehicle_plate'])
                    <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>Hoy:
                        @if($crew['vehicle_id'])<a href="{{ \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $crew['vehicle_id']]) }}" wire:navigate class="ck-link">{{ $crew['vehicle_plate'] }}</a>@else{{ $crew['vehicle_plate'] }}@endif
                    </span>
                @else
                    <span class="ck-pill">Sin tripulación hoy</span>
                @endif
                @if($crew && count($crew['helpers']))
                    <span class="ck-hub__sub">Ayudantes: {{ implode(', ', $crew['helpers']) }}</span>
                @endif
            </div>
        </div>

        {{-- ===== KPI STRIP ===== --}}
        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Entregas · 30d</div>
                <div class="ck-stat__value">{{ $hist ? number_format($hist['delivered']) : '—' }}</div>
                <div class="ck-stat__hint">{{ $hist ? $hist['total_stops'].' paradas · '.$hist['failed'].' fallidas' : 'Sin historial' }}</div>
            </div>
            <div class="ck-stat {{ $hist && $hist['effectiveness'] !== null ? ($hist['effectiveness'] >= 90 ? 'ck-stat--positive' : ($hist['effectiveness'] >= 75 ? 'ck-stat--warning' : 'ck-stat--danger')) : '' }}">
                <div class="ck-stat__label">Efectividad</div>
                <div class="ck-stat__value">{{ $hist && $hist['effectiveness'] !== null ? $hist['effectiveness'].'%' : '—' }}</div>
                <div class="ck-stat__hint">{{ $hist ? 'entregadas / cerradas' : 'Sin datos' }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Días trabajados · 30d</div>
                <div class="ck-stat__value">{{ $hist ? $hist['days_worked'] : '—' }}</div>
                <div class="ck-stat__hint">{{ $hist && count($hist['vehicles']) ? count($hist['vehicles']).' vehículos' : 'Sin salidas' }}</div>
            </div>
            <div class="ck-stat {{ $comp['expired'] > 0 || $licState === 'expired' ? 'ck-stat--danger' : ($comp['due_soon'] > 0 || $licState === 'due_soon' ? 'ck-stat--warning' : 'ck-stat--positive') }}">
                <div class="ck-stat__label">Documentos</div>
                <div class="ck-stat__value">{{ $compTotal > 0 ? $compTotal : 'OK' }}</div>
                <div class="ck-stat__hint">{{ $comp['expired'] > 0 ? $comp['expired'].' vencidos' : ($comp['due_soon'] > 0 ? $comp['due_soon'].' por vencer · 7d' : 'Al día') }}</div>
            </div>
        </div>

        {{-- ===== TABS ===== --}}
        <div class="ck-tabs">
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'personal' }" @click="t = 'personal'">Personal</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'license' }" @click="t = 'license'">Licencia y documentos<span class="ck-tab__n">{{ count($docs) }}</span></button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'activity' }" @click="t = 'activity'">Actividad<span class="ck-tab__n">{{ count($alerts) }}</span></button>
                <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'notes' }" @click="t = 'notes'">Notas<span class="ck-tab__n">{{ count($this->notes) }}</span></button>
        </div>

        {{-- ===== PANEL · RESUMEN ===== --}}
        <div x-show="t === 'summary'">

            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del chofer</div>
                    <div class="ck-hub__pills" style="margin-top:.7rem">
                        @foreach($this->managementLinks() as $m)
                            <a href="{{ $m['url'] }}" wire:navigate class="ck-pill ck-pill--agent">{{ $m['label'] }} →</a>
                        @endforeach
                    </div>
                    <div class="ck-hub__sub" style="margin-top:.7rem">Estos registros dependen de esta entidad: se gestionan desde su ficha, no desde el menú.</div>
                </div>
            @endif
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Desempeño · últimos 30 días</div>
                    @if($hist)
                        <div class="ck-card__title">{{ number_format($hist['delivered']) }} entregas</div>
                        <dl class="ck-dl" style="margin-top:1rem">
                            <dt>Paradas totales</dt><dd>{{ number_format($hist['total_stops']) }}</dd>
                            <dt>Entregadas</dt><dd>{{ number_format($hist['delivered']) }}</dd>
                            <dt>Fallidas</dt><dd>{{ number_format($hist['failed']) }}</dd>
                            <dt>Reprogramadas</dt><dd>{{ number_format($hist['rescheduled']) }}</dd>
                            <dt>Días trabajados</dt><dd>{{ $hist['days_worked'] }}</dd>
                        </dl>
                        @if(count($hist['vehicles']))
                            <div class="ck-hub__pills" style="margin-top:1rem">
                                @foreach($hist['vehicles'] as $plate)
                                    <span class="ck-pill">{{ $plate }}</span>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="ck-empty"><div class="ck-empty__title">Sin historial operativo</div><div class="ck-empty__hint">No hay rutas registradas en el periodo.</div></div>
                    @endif
                </div>

                <div class="ck-card">
                    <div class="ck-card__eyebrow">Tripulación de hoy</div>
                    @if($crew && ($crew['vehicle_plate'] || count($crew['helpers'])))
                        @if($crew['vehicle_plate'])
                            <div class="ck-card__title">@if($crew['vehicle_id'])<a href="{{ \Logistics\Fleet\Presentation\Filament\Resources\VehicleResource::getUrl('view', ['record' => $crew['vehicle_id']]) }}" wire:navigate class="ck-link">{{ $crew['vehicle_plate'] }}</a>@else{{ $crew['vehicle_plate'] }}@endif</div>
                        @endif
                        @if(count($crew['helpers']))
                            <div class="ck-events" style="margin-top:.8rem">
                                @foreach($crew['helpers'] as $helper)
                                    <div class="ck-event">
                                        <span class="ck-event__ic"></span>
                                        <span class="ck-event__tx">{{ $helper }}</span>
                                        <span class="ck-event__when">Ayudante</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="ck-hub__sub" style="margin-top:.6rem">Sin ayudantes asignados hoy.</div>
                        @endif
                    @else
                        <div class="ck-empty"><div class="ck-empty__title">Sin tripulación hoy</div><div class="ck-empty__hint">Este chofer no tiene una tripulación armada para hoy.</div></div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ===== PANEL · PERSONAL ===== --}}
        <div x-show="t === 'personal'" x-cloak>
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Datos personales</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>Documento (CI)</dt><dd class="ck-mono">{{ $d->national_id ?: '—' }}</dd>
                        <dt>Nacimiento</dt><dd>{{ $d->birth_date ? \Illuminate\Support\Carbon::parse($d->birth_date)->isoFormat('DD MMM YYYY') : '—' }}{{ $age !== null ? ' · '.$age.' años' : '' }}</dd>
                        <dt>Teléfono</dt><dd class="ck-mono">{{ $d->phone_primary ?: '—' }}</dd>
                        <dt>Teléfono 2</dt><dd class="ck-mono">{{ $d->phone_secondary ?: '—' }}</dd>
                        <dt>Dirección</dt><dd>{{ $d->address ?: '—' }}</dd>
                        <dt>Contacto emergencia</dt><dd>{{ $d->emergency_contact_name ?: '—' }}</dd>
                        <dt>Tel. emergencia</dt><dd class="ck-mono">{{ $d->emergency_contact_phone ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Referencias personales</div>
                    <div class="ck-events" style="margin-top:.6rem">
                        @forelse($references as $ref)
                            <div class="ck-event">
                                <span class="ck-event__ic"></span>
                                <span class="ck-event__tx">
                                    {{ is_array($ref) && isset($ref['name']) ? $ref['name'] : '—' }}
                                    @php $rel = is_array($ref) && isset($ref['relationship']) ? $ref['relationship'] : null; @endphp
                                    @if($rel)<small>{{ $rel }}</small>@endif
                                </span>
                                <span class="ck-event__when ck-mono">{{ is_array($ref) && isset($ref['phone']) ? $ref['phone'] : '—' }}</span>
                            </div>
                        @empty
                            <div class="ck-empty"><div class="ck-empty__title">Sin referencias</div><div class="ck-empty__hint">No se registraron referencias personales.</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== PANEL · LICENCIA Y DOCUMENTOS ===== --}}
        <div x-show="t === 'license'" x-cloak>
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Licencia de conducir</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>Número</dt><dd class="ck-mono">{{ $d->license_number ?: '—' }}</dd>
                        <dt>Categoría</dt><dd>{{ $d->license_category ?: '—' }}</dd>
                        <dt>Ciudad</dt><dd>{{ $d->license_city ?: '—' }}</dd>
                        <dt>Vencimiento</dt><dd>{{ $d->license_expiry ? \Illuminate\Support\Carbon::parse($d->license_expiry)->isoFormat('DD MMM YYYY') : '—' }}</dd>
                    </dl>
                    <div style="margin-top:1rem"><span class="ck-pill {{ $licTone ? 'ck-pill--'.$licTone : '' }}">Estado: {{ $licLbl }}</span></div>
                </div>
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Otros documentos</div>
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
                            <div class="ck-empty"><div class="ck-empty__title">Sin documentos</div><div class="ck-empty__hint">Registrá documentos del chofer desde el botón «Documentos».</div></div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== PANEL · ACTIVIDAD ===== --}}
        <div x-show="t === 'activity'" x-cloak>
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Entregas por día · últimos 30 días</div>
                @if($hist && array_sum($hist['series']['delivered']) > 0)
                    <x-cockpit.chart
                        type="bars"
                        :series="$hist['series']['delivered']"
                        :labels="$hist['series']['labels']"
                        tone="green"
                        :height="150"
                        label="Entregas/día"
                        :value="number_format($hist['delivered'])"
                        unit="30d" />
                @else
                    <div class="ck-empty"><div class="ck-empty__title">Sin entregas en el periodo</div><div class="ck-empty__hint">No hay entregas registradas en los últimos 30 días.</div></div>
                @endif
            </div>
            <div class="ck-card ck-section">
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
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Línea de tiempo</div>
                <div style="margin-top:.6rem"><x-cockpit.timeline :events="$this->timeline" /></div>
            </div>
        </div>


            {{-- ===== PANEL · NOTAS Y SEGUIMIENTOS ===== --}}
            <div x-show="t === 'notes'" x-cloak>
                <x-cockpit.notes :notes="$this->notes" />
            </div>

    </div>
</x-filament-panels::page>
