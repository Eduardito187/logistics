{{--
    Marco visual compartido para TODAS las páginas de error HTTP.

    Requisitos críticos:
    - Debe renderizar aunque fallen los assets o el servidor: NO depende de @vite ni de Vue.
      Todo el CSS va embebido en esta vista.
    - Consciente de sesión: el CTA cambia según @auth/@guest (ver más abajo).
    - Nunca filtra detalles internos (mensajes de excepción, stack, rutas): cada página hija
      solo pasa textos genéricos y amables.

    Variables que cada página hija define vía @section:
    - title: pestaña del navegador (corto).
    - code:  código HTTP grande (p. ej. 404).
    - heading: título corto en español.
    - message: mensaje amable en español.
    Opcional:
    - accent: color del código (por defecto el rojo de marca).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Error') · Dismac Logistics</title>
    <style>
        :root {
            --bg: #0a0a0a;
            --panel: #111111;
            --panel-border: #1f1f1f;
            --brand: #E2231A;
            --brand-hover: #D32F2F;
            --text: #f5f5f5;
            --muted: #9a9a9a;
            --btn-text: #ffffff;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto,
                "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .error-wrap {
            flex: 1 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 16px; /* gutter 16px en móvil */
        }

        .error-card {
            width: 100%;
            max-width: 560px;
            text-align: center;
            background: radial-gradient(120% 120% at 50% 0%, rgba(226, 35, 26, 0.08) 0%, transparent 60%);
            border-radius: 20px;
            padding: 8px 4px 16px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 40px;
            font-weight: 700;
            letter-spacing: 0.2px;
            font-size: 18px;
        }

        .brand .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 16px rgba(226, 35, 26, 0.7);
        }

        .brand .brand-name { color: var(--text); }
        .brand .brand-sub { color: var(--muted); font-weight: 500; }

        .code {
            font-size: clamp(88px, 28vw, 168px);
            line-height: 0.9;
            font-weight: 800;
            margin: 0;
            letter-spacing: -4px;
            color: var(--brand);
            text-shadow: 0 0 60px rgba(226, 35, 26, 0.25);
        }

        .heading {
            margin: 20px 0 10px;
            font-size: clamp(22px, 6vw, 28px);
            font-weight: 700;
            color: var(--text);
        }

        .message {
            margin: 0 auto 36px;
            max-width: 420px;
            font-size: 16px;
            line-height: 1.6;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 13px 26px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.05s ease;
        }

        .btn:active { transform: translateY(1px); }

        .btn-primary {
            background: var(--brand);
            color: var(--btn-text);
        }
        .btn-primary:hover { background: var(--brand-hover); }

        .btn-ghost {
            background: transparent;
            color: var(--text);
            border-color: var(--panel-border);
        }
        .btn-ghost:hover { border-color: #3a3a3a; }

        .footer {
            flex-shrink: 0;
            text-align: center;
            padding: 24px 16px 32px;
            font-size: 13px;
            color: #5f5f5f;
        }

        @media (prefers-reduced-motion: reduce) {
            .btn { transition: none; }
        }
    </style>
</head>
<body>
    <main class="error-wrap">
        <div class="error-card">
            <div class="brand">
                <span class="dot"></span>
                <span class="brand-name">Dismac</span>
                <span class="brand-sub">Logistics</span>
            </div>

            <p class="code" style="color: @yield('accent', 'var(--brand)')">@yield('code')</p>
            <h1 class="heading">@yield('heading')</h1>
            <p class="message">@yield('message')</p>

            <div class="actions">
                {{-- CTA consciente de sesión: autenticado -> panel; invitado -> login. --}}
                @auth
                    <a class="btn btn-primary" href="/admin">Ir al panel</a>
                @else
                    <a class="btn btn-primary" href="/admin/login">Iniciar sesión</a>
                @endauth

                @yield('extra-actions')
            </div>
        </div>
    </main>

    <footer class="footer">
        Dismac Logistics · Plataforma logística
    </footer>
</body>
</html>
