{{--
    Metadatos de <head> del panel DisTrack: íconos de app, manifest, color de tema (Noche #141414)
    y tarjeta Open Graph. Todos los recursos gráficos se referencian por URL del kit servido en
    public/brand/distrack (nunca se incrusta el contenido SVG inline: es dato no confiable del kit).
    El archivo /site.webmanifest lo genera otro agente; aquí solo se enlaza.
--}}

{{-- Favicon SVG moderno + respaldos PNG para navegadores sin soporte SVG. --}}
<link rel="icon" type="image/svg+xml" href="{{ asset('brand/distrack/favicon.svg') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/distrack/png/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('brand/distrack/png/favicon-16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('brand/distrack/png/favicon-180.png') }}">

{{-- Web App Manifest (lo provee otro agente) y color de tema: Noche. --}}
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#141414">

{{-- Open Graph: identidad del panel al compartir enlaces internos. --}}
<meta property="og:type" content="website">
<meta property="og:site_name" content="DisTrack">
<meta property="og:title" content="DisTrack">
<meta property="og:description" content="Panel logístico DisTrack. DE ANDÉN A PUERTA.">
<meta property="og:image" content="{{ asset('brand/distrack/png/favicon-512.png') }}">
