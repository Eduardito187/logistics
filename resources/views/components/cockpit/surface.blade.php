{{-- Envoltura de la superficie "andén" del cockpit: aísla el tema claro/cálido (ver theme.css
     `.distrack-cockpit`) del resto del admin Noche. Todo el contenido de una página cockpit va dentro. --}}
<div {{ $attributes->class(['distrack-cockpit']) }}>
    {{ $slot }}
</div>
