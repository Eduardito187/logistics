{{--
    Marco visual compartido para TODAS las páginas de error HTTP del panel interno.
    Marca: DisTrack · LOGÍSTICA — "DE ANDÉN A PUERTA."

    Requisitos críticos (no se negocian):
    - Debe renderizar aunque fallen los assets o el servidor: NO depende de @vite ni de Vue.
      Todo el CSS va embebido en esta vista. No hay recursos de orígenes externos: las fuentes de
      marca se auto-hospedan bajo /brand/distrack/fonts/ (self), con degradación elegante a
      fuentes del sistema si no cargan.
    - Consciente de sesión: el CTA cambia según @auth/@guest (ver más abajo).
    - Nunca filtra detalles internos (mensajes de excepción, stack, query string, datos
      sensibles): cada página hija solo pasa textos genéricos y amables, y la referencia de
      soporte expone únicamente la hora y la ruta (path) solicitada.
    - Los SVG de marca se referencian por URL desde /brand/distrack/... (nunca inline).
    - Tema claro/oscuro automático según el dispositivo (prefers-color-scheme).

    Variables que cada página hija define vía @section:
    - title:   pestaña del navegador y descriptor del error (corto).
    - code:    código HTTP grande dentro del visor de escaneo (p. ej. 404).
    - heading: título corto en español.
    - message: mensaje amable en español.
    Opcional:
    - extra-actions: botones adicionales (p. ej. "Reintentar", "Ir atrás").
--}}
@php
    // Referencia de soporte: solo hora y ruta. request()->path() no incluye query string,
    // por lo que no filtra parámetros ni datos sensibles.
    $ref  = strtoupper(substr(base_convert((string) now()->timestamp, 10, 36), -6));
    $when = now()->format('d/m/Y H:i');
    $path = '/' . ltrim(request()->path(), '/');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') · @yield('title', 'Error') · DisTrack</title>
    <link rel="icon" href="/brand/distrack/favicon.svg" type="image/svg+xml">
    {{-- Fuentes de marca DisTrack (OFL) auto-hospedadas como woff2 bajo /brand/distrack/fonts/
         (NO Google Fonts): así estas páginas de error no dependen de ningún origen externo y
         respetan la CSP 'self' (ADR 013 "sin Google"). Si un woff2 no carga, cae al fallback de
         sistema ya definido en --display/--body/--mono. --}}
    <style>
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-400.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-400-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-500.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-500-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-700.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-700-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 800;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-800.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Barlow';
            font-style: normal;
            font-weight: 800;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-800-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Barlow Condensed';
            font-style: normal;
            font-weight: 800;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-condensed-800.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'Barlow Condensed';
            font-style: normal;
            font-weight: 800;
            font-display: swap;
            src: url('/brand/distrack/fonts/barlow-condensed-800-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'IBM Plex Mono';
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url('/brand/distrack/fonts/ibm-plex-mono-500.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @font-face {
            font-family: 'IBM Plex Mono';
            font-style: normal;
            font-weight: 500;
            font-display: swap;
            src: url('/brand/distrack/fonts/ibm-plex-mono-500-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
    </style>
    <style>
        :root {
            /* Paleta de marca DisTrack — tema claro (Andén) */
            --bg: #F3F3F1;        /* Andén */
            --surface: #FFFFFF;
            --ink: #141414;        /* Noche */
            --muted: #5C5C5C;
            --line: #DCDCD8;
            --red: #E30613;        /* Rojo DisTrack */
            --red-ink: #FFFFFF;
            --amber: #FFB000;      /* Ámbar */
            --display: "Barlow Condensed", "Arial Narrow", Arial, sans-serif;
            --body: "Barlow", "Segoe UI", Roboto, Arial, sans-serif;
            --mono: "IBM Plex Mono", ui-monospace, Consolas, monospace;
            color-scheme: light;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                /* Tema oscuro — fondo Noche #141414 */
                --bg: #141414;
                --surface: #1A1A1C;
                --ink: #F2F2F0;
                --muted: #A3A3A0;
                --line: #2C2C2F;
                color-scheme: dark;
            }
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 400 17px/1.55 var(--body);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            /* Gutter lateral de 16px como mínimo, respetando notch */
            padding-left: max(16px, env(safe-area-inset-left, 0px));
            padding-right: max(16px, env(safe-area-inset-right, 0px));
        }

        /* Encabezado: logo DisTrack + descriptor LOGÍSTICA con barra separadora */
        header {
            padding-block: 22px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        header .logo { height: 30px; width: auto; display: block; }
        header .logo-dark { display: none; }
        header .divider { width: 1px; height: 22px; background: var(--line); }
        header .descriptor {
            font: 500 12px/1 var(--mono);
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--muted);
        }
        @media (prefers-color-scheme: dark) {
            header .logo-light { display: none; }
            header .logo-dark { display: block; }
        }

        main {
            flex: 1;
            display: grid;
            grid-template-columns: auto minmax(0, 34rem);
            gap: clamp(28px, 6vw, 80px);
            align-items: center;
            align-content: center;
            max-width: 64rem;
            width: 100%;
            margin-inline: auto;
            padding-block: 48px;
        }

        /* Visor de escaneo: cuatro esquinas + línea que recorre el código */
        .frame {
            position: relative;
            padding: 26px 34px 22px;
            display: grid;
            justify-items: center;
            gap: 10px;
        }
        .frame i { position: absolute; width: 34px; height: 34px; border: 0 solid var(--red); }
        .frame i:nth-child(1) { top: 0; left: 0; border-top-width: 10px; border-left-width: 10px; }
        .frame i:nth-child(2) { top: 0; right: 0; border-top-width: 10px; border-right-width: 10px; }
        .frame i:nth-child(3) { bottom: 0; left: 0; border-bottom-width: 10px; border-left-width: 10px; }
        .frame i:nth-child(4) { bottom: 0; right: 0; border-bottom-width: 10px; border-right-width: 10px; }

        .code {
            font: 800 clamp(96px, 16vw, 168px)/0.85 var(--display);
            letter-spacing: -0.01em;
            font-variant-numeric: tabular-nums;
            color: var(--ink);
        }

        /* Código de barras decorativo bajo el número (puramente visual) */
        .bc { width: 100%; max-width: 220px; height: 30px; color: var(--ink); }

        /* Línea de escaneo animada */
        .scan {
            position: absolute;
            left: 18px; right: 18px;
            height: 3px;
            background: var(--red);
            top: 50%;
            opacity: .85;
            animation: scan 2.4s cubic-bezier(.6,0,.3,1) infinite alternate;
        }
        @keyframes scan { from { top: 16%; } to { top: 84%; } }
        @media (prefers-reduced-motion: reduce) { .scan { animation: none; } }

        .eyebrow {
            font: 500 13px/1 var(--mono);
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--red);
            margin: 0 0 14px;
        }
        h1 {
            font: 800 clamp(30px, 4.4vw, 44px)/1.05 var(--body);
            letter-spacing: -0.015em;
            margin: 0 0 14px;
            text-wrap: balance;
            color: var(--ink);
        }
        p.lead { color: var(--muted); margin: 0 0 28px; max-width: 34rem; }

        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .btn {
            display: inline-flex;
            align-items: center;
            min-height: 52px;
            padding: 0 22px;
            border-radius: 6px;
            font: 700 16px/1 var(--body);
            text-decoration: none;
            color: var(--ink);
            border: 2px solid var(--ink);
            background: transparent;
            cursor: pointer;
        }
        .btn.primary { background: var(--red); border-color: var(--red); color: var(--red-ink); }
        .btn:hover { filter: brightness(1.08); }
        .btn:focus-visible { outline: 3px solid var(--red); outline-offset: 3px; }

        footer {
            border-top: 1px solid var(--line);
            padding-block: 16px;
            font: 500 12px/1.4 var(--mono);
            color: var(--muted);
            display: flex;
            flex-wrap: wrap;
            gap: 6px 24px;
            letter-spacing: .03em;
        }

        @media (max-width: 720px) {
            main { grid-template-columns: minmax(0, 1fr); justify-items: start; padding-block: 32px; }
        }
    </style>
</head>
<body>
<header>
    {{-- Logo DisTrack referenciado por URL (nunca inline). Variante clara/oscura por CSS. --}}
    <img class="logo logo-light" src="/brand/distrack/distrack-logo.svg" alt="DisTrack" width="195" height="30">
    <img class="logo logo-dark" src="/brand/distrack/distrack-logo-inverse.svg" alt="DisTrack" width="195" height="30">
    <span class="divider" aria-hidden="true"></span>
    <span class="descriptor">Logística</span>
</header>

<main>
    {{-- Visor de escaneo con el código de error dentro --}}
    <div class="frame" aria-hidden="true">
        <i></i><i></i><i></i><i></i><span class="scan"></span>
        <div class="code">@yield('code')</div>
        <svg class="bc" viewBox="0 0 220 34" fill="currentColor">
            <rect x="0" y="0" width="4" height="34"/><rect x="7" y="0" width="2" height="34"/>
            <rect x="11" y="0" width="6" height="34"/><rect x="21" y="0" width="2" height="34"/>
            <rect x="27" y="0" width="6" height="34"/><rect x="37" y="0" width="2" height="34"/>
            <rect x="42" y="0" width="6" height="34"/><rect x="51" y="0" width="6" height="34"/>
            <rect x="60" y="0" width="3" height="34"/><rect x="66" y="0" width="4" height="34"/>
            <rect x="72" y="0" width="6" height="34"/><rect x="81" y="0" width="2" height="34"/>
            <rect x="87" y="0" width="3" height="34"/><rect x="93" y="0" width="6" height="34"/>
            <rect x="103" y="0" width="3" height="34"/><rect x="110" y="0" width="3" height="34"/>
            <rect x="116" y="0" width="4" height="34"/><rect x="123" y="0" width="6" height="34"/>
            <rect x="131" y="0" width="2" height="34"/><rect x="137" y="0" width="2" height="34"/>
            <rect x="141" y="0" width="3" height="34"/><rect x="146" y="0" width="4" height="34"/>
            <rect x="154" y="0" width="3" height="34"/><rect x="161" y="0" width="4" height="34"/>
            <rect x="167" y="0" width="3" height="34"/><rect x="174" y="0" width="3" height="34"/>
            <rect x="181" y="0" width="6" height="34"/><rect x="189" y="0" width="3" height="34"/>
            <rect x="194" y="0" width="2" height="34"/><rect x="199" y="0" width="4" height="34"/>
            <rect x="205" y="0" width="2" height="34"/><rect x="211" y="0" width="2" height="34"/>
            <rect x="215" y="0" width="3" height="34"/>
        </svg>
    </div>

    <div>
        <p class="eyebrow">Error @yield('code') · @yield('title')</p>
        <h1>@yield('heading')</h1>
        <p class="lead">@yield('message')</p>

        <div class="actions">
            {{-- CTA consciente de sesión: autenticado -> panel; invitado -> login. --}}
            @auth
                <a class="btn primary" href="/admin">Ir al panel</a>
            @else
                <a class="btn primary" href="/admin/login">Iniciar sesión</a>
            @endauth

            @yield('extra-actions')
        </div>
    </div>
</main>

<footer>
    {{-- Referencia de soporte: solo código, hora y ruta. Sin stack ni detalles internos. --}}
    <span>Código de referencia (para soporte): ERR-@yield('code')-{{ $ref }}</span>
    <span>{{ $when }}</span>
    <span>{{ $path }}</span>
    <span style="color: var(--red); margin-left: auto;">DisTrack · De andén a puerta.</span>
</footer>
</body>
</html>
