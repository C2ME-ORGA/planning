<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property Carbon|null $last_login_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'role'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isAdministrator(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isBureauDirection(): bool
    {
        return $this->role === Role::BureauDirection;
    }

    /**
     * Vérifie une capacité côté serveur : compte actif, rôle, puis exception
     * temporaire non révoquée et non expirée.
     */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->role->hasPermission($permission)) {
            return true;
        }

        return $this->activePermissionExceptions()
            ->where('permission', $permission->value)
            ->exists();
    }

    /**
     * @return HasMany<AccountActivation, $this>
     */
    public function activations(): HasMany
    {
        return $this->hasMany(AccountActivation::class);
    }

    /**
     * @return HasMany<PermissionException, $this>
     */
    public function permissionExceptions(): HasMany
    {
        return $this->hasMany(PermissionException::class);
    }

    /**
     * @return Builder<PermissionException>
     */
    public function activePermissionExceptions(): Builder
    {
        return $this->permissionExceptions()->getQuery()->active();
    }

    public function requiresMultiFactorAuthentication(): bool
    {
        return $this->isActive() && $this->role->requiresMultiFactorAuthentication();
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeBureauDirection(Builder $query): void
    {
        $query->where('role', Role::BureauDirection->value);
    }

    /**
     * Accès au panneau Filament réservé aux rôles qui y ont une fonction.
     * Les autres rôles utiliseront les interfaces mobile des lots suivants.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActive() && $this->role->canAccessAdministrationPanel();
    }
}
