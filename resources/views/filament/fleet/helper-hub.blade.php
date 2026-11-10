{{--
    Hub 360 de ayudante — cockpit de detalle (DisTrack OS). Agrega: identidad + estado, datos personales y
    referencias (del registro), documentos con vencimiento y tripulaciones en las que participa (cross-context
    vía App\Support\Cockpit\HelperHub, $this->hub), más notas y línea de tiempo. Capa `.distrack-cockpit`.
    Español directo; NUNCA UUID visible. Conserva el chrome de página Filament (cabecera + acciones).
--}}
<x-filament-panels::page>
    @php
        /** @var \Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel $h */
        $h = $this->record;
        $hub = $this->hub;
        $comp = $hub['compliance'];
        $docs = $hub['documents'];
        $crews = $hub['crews'];

        $ph = \Logistics\Shared\Presentation\Filament\ImageFallback::placeholder('avatar');

        $age = null;
        if ($h->birth_date) {
            try { $age = \Illuminate\Support\Carbon::parse($h->birth_date)->age; } catch (\Throwable) { $age = null; }
        }

        $docState = [
            'expired' => ['Vencido', 'danger', 'ck-event__ic--red'],
            'due_soon' => ['Por vencer', 'warning', 'ck-event__ic--amber'],
            'upcoming' => ['Próximo', '', ''],
            'ok' => ['Vigente', 'positive', ''],
        ];

        $compTotal = $comp['expired'] + $comp['due_soon'];
        $statusLabel = $h->active ? 'Activo' : 'Inactivo';
        $statusTone = $h->active ? 'positive' : '';
        $references = is_array($h->references) ? $h->references : [];
    @endphp

    <div class="distrack-cockpit" x-data="{ t: 'summary' }">

        {{-- ===== HERO ===== --}}
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <img class="ck-hub__photo" src="{{ $this->photoUrl($h->photo_path) ?? $ph }}" alt=""
                     onerror="this.onerror=null;this.src='{{ $ph }}'">
                <div style="min-width:0">
                    <div class="ck-hub__title">{{ $h->name }}</div>
                    <div class="ck-hub__sub">
                        Ayudante{{ $age !== null ? ' · '.$age.' años' : '' }}{{ $h->national_id ? ' · CI '.$h->national_id : '' }}
                    </div>
                    <div class="ck-hub__pills">
                        <span class="ck-pill {{ $statusTone ? 'ck-pill--'.$statusTone : '' }}">{{ $statusLabel }}</span>
                        @if($h->phone)<span class="ck-pill ck-mono">{{ $h->phone }}</span>@endif
                    </div>
                </div>
            </div>
            <div class="ck-hub__aside">
                @if(count($crews))
                    <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ count($crews) }} tripulaciones</span>
                @else
                    <span class="ck-pill">Sin tripulaciones recientes</span>
                @endif
            </div>
        </div>

        {{-- ===== KPI STRIP ===== --}}
        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Tripulaciones</div>
                <div class="ck-stat__value">{{ count($crews) }}</div>
                <div class="ck-stat__hint">recientes</div>
            </div>
            <div class="ck-stat {{ $comp['expired'] > 0 ? 'ck-stat--danger' : ($comp['due_soon'] > 0 ? 'ck-stat--warning' : 'ck-stat--positive') }}">
                <div class="ck-stat__label">Documentos</div>
                <div class="ck-stat__value">{{ $compTotal > 0 ? $compTotal : 'OK' }}</div>
                <div class="ck-stat__hint">{{ $comp['expired'] > 0 ? $comp['expired'].' vencidos' : ($comp['due_soon'] > 0 ? $comp['due_soon'].' por vencer · 7d' : 'Al día') }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Referencias</div>
                <div class="ck-stat__value">{{ count($references) }}</div>
            </div>
            <div class="ck-stat {{ $statusTone ? 'ck-stat--'.$statusTone : '' }}">
                <div class="ck-stat__label">Estado</div>
                <div class="ck-stat__value" style="font-size:1.3rem">{{ $statusLabel }}</div>
            </div>
        </div>

        {{-- ===== TABS ===== --}}
        <div class="ck-tabs">
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'summary' }" @click="t = 'summary'">Resumen</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'personal' }" @click="t = 'personal'">Personal</button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'documents' }" @click="t = 'documents'">Documentos<span class="ck-tab__n">{{ count($docs) }}</span></button>
            <button type="button" class="ck-tab" :class="{ 'ck-tab--on': t === 'notes' }" @click="t = 'notes'">Notas<span class="ck-tab__n">{{ count($this->notes) }}</span></button>
        </div>

        {{-- ===== PANEL · RESUMEN ===== --}}
        <div x-show="t === 'summary'">
            @if(count($this->managementLinks()))
                <div class="ck-card ck-section">
                    <div class="ck-card__eyebrow">Gestión del ayudante</div>
                    <div class="ck-hub__pills" style="margin-top:.7rem">
                        @foreach($this->managementLinks() as $m)
                            <a href="{{ $m['url'] }}" wire:navigate class="ck-pill ck-pill--agent">{{ $m['label'] }} →</a>
                        @endforeach
                    </div>
                    <div class="ck-hub__sub" style="margin-top:.7rem">Estos registros dependen del ayudante: se gestionan desde su ficha, no desde el menú.</div>
                </div>
            @endif

            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Tripulaciones recientes</div>
                <div class="ck-events" style="margin-top:.6rem">
                    @forelse($crews as $c)
                        <div class="ck-event">
                            <span class="ck-event__ic"></span>
                            <span class="ck-event__tx">{{ $c['vehicle_plate'] }}</span>
                            <span class="ck-event__when">{{ $c['date'] ?: '—' }}</span>
                        </div>
                    @empty
                        <div class="ck-empty"><div class="ck-empty__title">Sin tripulaciones</div><div class="ck-empty__hint">Este ayudante no participó en tripulaciones recientes.</div></div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ===== PANEL · PERSONAL ===== --}}
        <div x-show="t === 'personal'" x-cloak>
            <div class="ck-grid ck-grid--2 ck-section">
                <div class="ck-card">
                    <div class="ck-card__eyebrow">Datos personales</div>
                    <dl class="ck-dl" style="margin-top:.9rem">
                        <dt>Documento (CI)</dt><dd class="ck-mono">{{ $h->national_id ?: '—' }}</dd>
                        <dt>Nacimiento</dt><dd>{{ $h->birth_date ? \Illuminate\Support\Carbon::parse($h->birth_date)->isoFormat('DD MMM YYYY') : '—' }}{{ $age !== null ? ' · '.$age.' años' : '' }}</dd>
                        <dt>Teléfono</dt><dd class="ck-mono">{{ $h->phone ?: '—' }}</dd>
                        <dt>Teléfono 2</dt><dd class="ck-mono">{{ $h->phone_secondary ?: '—' }}</dd>
                        <dt>Dirección</dt><dd>{{ $h->address ?: '—' }}</dd>
                        <dt>Contacto emergencia</dt><dd>{{ $h->emergency_contact_name ?: '—' }}</dd>
                        <dt>Tel. emergencia</dt><dd class="ck-mono">{{ $h->emergency_contact_phone ?: '—' }}</dd>
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

        {{-- ===== PANEL · DOCUMENTOS ===== --}}
        <div x-show="t === 'documents'" x-cloak>
            <div class="ck-card ck-section">
                <div class="ck-card__eyebrow">Documentos del ayudante</div>
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
                        <div class="ck-empty"><div class="ck-empty__title">Sin documentos</div><div class="ck-empty__hint">Registrá documentos del ayudante desde el botón «Documentos».</div></div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ===== PANEL · NOTAS ===== --}}
        <div x-show="t === 'notes'" x-cloak>
            <x-cockpit.notes :notes="$this->notes" />
        </div>

    </div>
</x-filament-panels::page>
