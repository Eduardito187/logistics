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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $driver_id UUID del chofer de Fleet vinculado a esta cuenta (null si no es chofer).
 * @property string|null $node_id   UUID del nodo de tienda (network_nodes) al que pertenece la cuenta (null si no es tienda).
 */
#[Fillable(['name', 'email', 'password', 'driver_id', 'node_id'])]
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
     * Nodo de tienda al que pertenece esta cuenta, en forma primitiva (id, code, name, type).
     *
     * Enriquecimiento cross-context por lectura directa: se consulta `network_nodes` (contexto Network)
     * sin acoplar el modelo al dominio de red, igual que el resto del admin lee esa tabla por consulta
     * directa (cada contexto es extraíble a microservicio; al extraerse pasará a una API de red).
     * Devuelve null si la cuenta no tiene nodo o si el nodo ya no existe.
     *
     * Devuelve el Node COMPLETO (todos los campos que el schema `Node` del openapi declara requeridos:
     * id, code, name, type, lat, lng, status, + department opcional), para que el cliente generado de la
     * app desde el contrato no reviente por campos faltantes.
     *
     * @return array{id: string, code: string, name: string, type: string, lat: float, lng: float, status: string, department: string|null}|null
     */
    public function assignedNode(): ?array
    {
        if ($this->node_id === null) {
            return null;
        }

        $node = DB::table('network_nodes')
            ->select('id', 'code', 'name', 'type', 'lat', 'lng', 'status', 'department')
            ->where('id', $this->node_id)
            ->first();

        if ($node === null) {
            return null;
        }

        return [
            'id' => (string) $node->id,
            'code' => (string) $node->code,
            'name' => (string) $node->name,
            'type' => (string) $node->type,
            'lat' => (float) $node->lat,
            'lng' => (float) $node->lng,
            'status' => (string) $node->status,
            'department' => $node->department !== null ? (string) $node->department : null,
        ];
    }

    /**
     * Acceso al panel de administración (Filament).
     *
     * El admin es el ÚNICO frontend de la plataforma: cada rol entra y ve SOLO las secciones que le
     * corresponden (el gating de navegación por rol vive en cada Resource/Page vía su `canAccess()`:
     * el concern `OfficeOnly` de `Logistics\Shared\...\Concerns` para oficina, `AdminOnly` del contexto
     * access para Administration, y `canAccess()` propio para los recursos de campo). Por eso cualquier cuenta
     * con al menos un rol operativo puede entrar; el landing es la torre de control (accesible a todos,
     * con contenido gateado por rol), así ningún rol recibe un 403 al iniciar sesión. Una cuenta SIN
     * rol queda fuera por defecto (seguro).
     *
     * Matriz rol → acceso (resumen; el detalle por sección está en cada Resource/Page):
     *   - Admin ........... TODO el panel, incluida Administration (seguridad, config, infraestructura).
     *   - Supervisor ...... oficina: Operations, Fleet, Network, Commerce (NO Administration).
     *   - Chofer .......... mínimo de campo: entregas, rutas, escaneos, seguimiento, tablero de última milla.
     *   - Preparador ...... mínimo de campo: escaneos de preparación.
     *   - Tienda .......... mínimo de campo: recepción en nodo y seguimiento de envíos.
     *   - Transportista ... mínimo de campo: seguimiento de envíos.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(
            Role::Admin,
            Role::Supervisor,
            Role::Chofer,
            Role::Preparador,
            Role::Tienda,
            Role::Transportista,
        );
    }
}
