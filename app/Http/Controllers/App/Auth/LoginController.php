<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App web (Inertia) — login propio del SPA. Reemplaza el reuso del login de Filament: la operación
 * interactiva (/app) autentica contra el guard web con su propia pantalla de ingreso. Solo sesión; las
 * credenciales se validan aquí y el resto de la app sigue protegida por el middleware 'auth'.
 */
final class LoginController extends Controller
{
    /**
     * Muestra el formulario de ingreso. Si ya hay sesión activa, va directo al panel.
     */
    public function show(): Response | RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('app.dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * Procesa el ingreso: valida credenciales, aplica rate limiting básico por email+IP y, si son
     * correctas, regenera la sesión y redirige a la ruta pretendida (o al panel).
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = $request->boolean('remember');
        $throttleKey = $this->throttleKey($request);

        // Rate limiting básico: 5 intentos por combinación email+IP antes de bloquear 60 s.
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Inténtalo de nuevo en {$seconds} segundos.",
            ]);
        }

        if (!Auth::guard('web')->attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $remember,
        )) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('app.dashboard'));
    }

    /**
     * Cierra la sesión del guard web e invalida la sesión actual.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.login');
    }

    /**
     * Clave de throttling para los intentos de ingreso: email normalizado + IP del cliente.
     */
    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }
}
