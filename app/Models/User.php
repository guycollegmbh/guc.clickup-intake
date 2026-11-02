<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * role and active are deliberately not fillable: only the make-admin command,
 * the setup assistant and admins in the panel change them explicitly.
 */
#[Fillable(['name', 'email', 'entra_object_id', 'mailbox_upn'])]
#[Hidden(['remember_token', 'ms_refresh_token', 'ms_access_token', 'clickup_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'active' => 'boolean',
            'ms_refresh_token' => 'encrypted',
            'ms_access_token' => 'encrypted',
            'ms_token_expires_at' => 'datetime',
            'ms_connection_status' => ConnectionStatus::class,
            'ms_connected_at' => 'datetime',
            'clickup_token' => 'encrypted',
            'clickup_connection_status' => ConnectionStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * There are no local passwords (Microsoft only, R11). Laravel's session guard and
     * AuthenticateSession read the password hash, an empty one disables that part.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * @return HasMany<IntakeItem, $this>
     */
    public function intakeItems(): HasMany
    {
        return $this->hasMany(IntakeItem::class);
    }
}
