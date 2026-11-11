<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Login/logout de la APP DE CAMPO del chofer (Vue+Inertia, superficie móvil). Sesión web estándar de
 * Laravel (mismo users/password que el admin), pero con su propia página en Vue y redirección propia.
 * Solo entran cuentas ligadas a un chofer de la flota (driver_id).
 */
final class DriverAuthController
{
    public function show(): InertiaResponse | RedirectResponse
    {
        // Si ya hay sesión de chofer, directo a su ruta.
        if (Auth::check() && Auth::user() instanceof User && Auth::user()->driver_id !== null) {
            return redirect('/app/driver');
        }

        return Inertia::render('Driver/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt(['email' => $data['email'], 'password' => $data['password']], true)) {
            throw ValidationException::withMessages(['email' => 'Credenciales inválidas.']);
        }

        $user = Auth::user();
        if (!$user instanceof User || $user->driver_id === null) {
            // Cuenta válida pero no es un chofer: no entra a la app de campo.
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Esta cuenta no es de un chofer.']);
        }

        $request->session()->regenerate();

        return redirect('/app/driver');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/app/login');
    }
}
