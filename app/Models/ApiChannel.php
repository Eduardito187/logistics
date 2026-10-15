<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Canal consumidor de `/api/v1/channels/*` (P1-AUTH-04).
 *
 * Representa a la app de clientes, la web o el call center. Cada canal se autentica
 * con un par `key` (público, viaja en header) + `secret` (solo su hash se persiste) y
 * tiene su propio límite de peticiones por minuto (`rate_limit_per_minute`).
 *
 * @property int         $id
 * @property string      $name
 * @property string      $key
 * @property string      $secret
 * @property int         $rate_limit_per_minute
 * @property bool        $active
 * @property Carbon|null $last_used_at
 */
class ApiChannel extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'key',
        'secret',
        'rate_limit_per_minute',
        'active',
        'last_used_at',
    ];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'hashed',
            'rate_limit_per_minute' => 'integer',
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Genera una `key` pública única (prefijo `ch_`).
     */
    public static function generateKey(): string
    {
        return 'ch_'.Str::lower(Str::random(28));
    }

    /**
     * Genera un `secret` en claro (prefijo `sk_`); el llamador debe mostrarlo una sola
     * vez y guardarlo en el modelo (se persistirá como hash por el cast 'hashed').
     */
    public static function generateSecret(): string
    {
        return 'sk_'.Str::random(48);
    }

    /**
     * Verifica un secreto en claro contra el hash almacenado.
     */
    public function verifySecret(string $plainSecret): bool
    {
        return Hash::check($plainSecret, $this->secret);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
