<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\InvalidActivationException;
use App\Models\AccountActivation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Création de compte et activation par lien unique.
 *
 * Aucun mot de passe n'est choisi par le Bureau / Direction : le compte reçoit
 * un mot de passe aléatoire inutilisable, puis la personne définit elle-même
 * son mot de passe via un jeton aléatoire stocké haché, à usage unique et
 * expirant.
 */
class AccountProvisioner
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountActivationService $activations,
    ) {}

    public function create(string $name, string $email, Role $role, ?User $actor = null): User
    {
        if ($actor !== null && (! $actor->hasPermission(Permission::AccountsCreate)
            || ! $actor->hasPermission(Permission::RolesAssign))) {
            throw new RuntimeException('Ce compte n’est pas autorisé à créer un compte.');
        }

        $email = Str::lower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException('Une adresse e-mail identique existe déjà.');
        }

        return DB::transaction(function () use ($name, $email, $role, $actor): User {
            $user = new User([
                'name' => trim($name),
                'email' => $email,
                'role' => $role,
            ]);

            $user->is_active = true;
            $user->password = Str::password(64);
            $user->save();

            $this->audit->record(
                AuditAction::AccountCreated,
                $user,
                ['role' => $role->value],
                null,
                $actor,
            );

            $this->activations->send($user, $actor);

            return $user->refresh();
        });
    }

    /**
     * Point d'entrée réservé au bootstrap contrôlé du premier compte
     * Bureau / Direction. Il refuse toute promotion d'un compte existant.
     */
    public function bootstrapBureauDirection(string $name, string $email): User
    {
        if (User::query()->bureauDirection()->exists()) {
            throw new RuntimeException('Un compte Bureau / Direction existe déjà : le bootstrap est clos.');
        }

        $user = $this->create($name, $email, Role::BureauDirection);

        $this->audit->record(
            AuditAction::BootstrapBureauCreated,
            $user,
            ['role' => Role::BureauDirection->value],
            'Initialisation contrôlée du premier compte Bureau / Direction.',
        );

        return $user;
    }

    public function activate(AccountActivation $activation, string $password): User
    {
        return DB::transaction(function () use ($activation, $password): User {
            $locked = AccountActivation::query()
                ->whereKey($activation->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof AccountActivation || ! $locked->isUsable()) {
                throw new InvalidActivationException('Ce lien d’activation est invalide ou expiré.');
            }

            $user = $locked->user;

            if (! $user instanceof User || ! $user->isActive()) {
                throw new InvalidActivationException('Ce compte ne peut pas être activé.');
            }

            $user->password = $password;
            $user->forceFill([
                'email_verified_at' => now(),
                'activated_at' => now(),
            ])->save();

            $locked->forceFill(['consumed_at' => now()])->save();

            $this->audit->record(
                AuditAction::AccountActivated,
                $user,
                ['activated_at' => now()->toIso8601String()],
                null,
                $user,
            );

            return $user->refresh();
        });
    }
}
