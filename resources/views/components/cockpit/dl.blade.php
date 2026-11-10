{{-- Lista de pares etiqueta/valor para hubs. `rows` = list<[label, value]> o se compone con el slot. --}}
@props(['rows' => []])
<dl {{ $attributes->class(['ck-dl']) }}>
    @foreach ($rows as $row)
        <dt>{{ $row[0] ?? '' }}</dt><dd>{{ $row[1] ?? '—' }}</dd>
    @endforeach
    {{ $slot }}
</dl>
