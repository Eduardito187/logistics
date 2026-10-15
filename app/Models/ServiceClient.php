<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cliente de servicio de `/api/v1/integrations/*` (P1-AUTH-05).
 *
 * Representa a un sistema que integra servidor-a-servidor: SAP, un transportista o un
 * emisor de webhooks. Se autentica con `client_id` (público) + `client_secret` (solo
 * hash) y declara los contextos a los que puede acceder (`scopes`).
 *
 * @property int          $id
 * @property string       $name
 * @property string       $client_id
 * @property string       $client_secret
 * @property list<string> $scopes
 * @property bool         $active
 * @property Carbon|null  $last_used_at
 */
class ServiceClient extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'scopes',
        'active',
        'last_used_at',
    ];

    /** @var list<string> */
    protected $hidden = ['client_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_secret' => 'hashed',
            'scopes' => 'array',
            'active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public static function generateClientId(): string
    {
        return 'svc_'.Str::lower(Str::random(28));
    }

    public static function generateClientSecret(): string
    {
        return 'secret_'.Str::random(48);
    }

    public function verifySecret(string $plainSecret): bool
    {
        return Hash::check($plainSecret, $this->client_secret);
    }

    /**
     * ¿El cliente tiene concedido este scope? El scope '*' concede todos.
     */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
