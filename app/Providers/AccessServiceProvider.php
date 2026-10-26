<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Listeners\AuditAuthenticationEvents;
use App\Models\AuditEvent;
use App\Models\PermissionException;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\AuditEventPolicy;
use App\Policies\PermissionExceptionPolicy;
use App\Policies\UserPolicy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Source unique des autorisations : une capacité = une gate vérifiée côté
 * serveur, alimentée par le rôle et les exceptions temporaires du compte.
 */
class AccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                static fn (User $user): bool => $user->hasPermission($permission),
            );
        }

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(PermissionException::class, PermissionExceptionPolicy::class);

        User::observe(UserObserver::class);

        Event::listen(Login::class, [AuditAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [AuditAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [AuditAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [AuditAuthenticationEvents::class, 'handlePasswordReset']);
        Event::listen(PasswordResetLinkSent::class, [AuditAuthenticationEvents::class, 'handlePasswordResetLinkSent']);
    }
}
