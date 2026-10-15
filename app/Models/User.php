<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Models\Concerns\HasRoles;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Dispositivos ligados a este usuario (binding usuario↔dispositivo).
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Acceso al panel de administración (Filament).
     *
     * El backoffice es para roles de supervisión y administración; los roles de campo
     * (chofer, preparador, tienda, transportista) operan desde la app Flutter, no desde el admin.
     * Por eso solo entran las cuentas con rol Admin o Supervisor. Una cuenta sin esos roles queda
     * fuera por defecto (seguro): se le concede acceso asignándole el rol correspondiente.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(Role::Admin, Role::Supervisor);
    }
}
