<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Listeners\AuditAuthenticationEvents;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Site;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\PermissionException;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\TeamLeadership;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\AuditEventPolicy;
use App\Policies\ClientPolicy;
use App\Policies\SitePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmploymentPeriodPolicy;
use App\Policies\PermissionExceptionPolicy;
use App\Policies\TeamAssignmentPolicy;
use App\Policies\TeamLeadershipPolicy;
use App\Policies\TeamPolicy;
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
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(PermissionException::class, PermissionExceptionPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(EmploymentPeriod::class, EmploymentPeriodPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(TeamAssignment::class, TeamAssignmentPolicy::class);
        Gate::policy(TeamLeadership::class, TeamLeadershipPolicy::class);

        User::observe(UserObserver::class);

        Event::listen(Login::class, [AuditAuthenticationEvents::class, 'handleLogin']);
        Event::listen(Failed::class, [AuditAuthenticationEvents::class, 'handleFailed']);
        Event::listen(Logout::class, [AuditAuthenticationEvents::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [AuditAuthenticationEvents::class, 'handlePasswordReset']);
        Event::listen(PasswordResetLinkSent::class, [AuditAuthenticationEvents::class, 'handlePasswordResetLinkSent']);
    }
}
