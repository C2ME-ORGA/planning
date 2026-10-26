<?php

namespace App\Enums;

/**
 * Rôles fonctionnels du MVP.
 *
 * Un compte ne possède qu'un seul rôle. L'Administrateur est un rôle technique :
 * il ne reçoit aucune capacité métier par défaut (voir ROADMAP lot 2 et
 * docs/SECURITY.md).
 */
enum Role: string
{
    case Admin = 'administrateur';
    case BureauDirection = 'bureau_direction';
    case Conducteur = 'conducteur_travaux';
    case ChefEquipe = 'chef_equipe';
    case Ouvrier = 'ouvrier';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::BureauDirection => 'Bureau / Direction',
            self::Conducteur => 'Conducteur de travaux',
            self::ChefEquipe => 'Chef d’équipe',
            self::Ouvrier => 'Ouvrier',
        };
    }

    /**
     * Capacités accordées par le rôle, hors exceptions temporaires.
     *
     * Les capacités métier sont posées ici pour que la matrice d'accès du lot 2
     * soit vérifiable ; chaque lot ultérieur doit les appliquer dans ses
     * policies et actions métier.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Rôle technique : lecture du journal uniquement, jamais de droit métier.
            self::Admin => [
                Permission::AuditView,
            ],

            self::BureauDirection => [
                Permission::AccountsView,
                Permission::AccountsCreate,
                Permission::AccountsUpdate,
                Permission::AccountsDeactivate,
                Permission::AccountsReactivate,
                Permission::AccountsResendActivation,
                Permission::RolesAssign,
                Permission::AuditView,
                Permission::PermissionExceptionsManage,
                Permission::PlanningManage,
                Permission::TimesheetValidate,
                Permission::WorkflowCorrect,
                Permission::WorkflowReopen,
                Permission::AbsenceManage,
            ],

            self::Conducteur => [
                Permission::PlanningPropose,
                Permission::TimesheetControl,
                Permission::TimesheetOwn,
                Permission::AvailabilityViewPerimeter,
                Permission::AbsenceOwnView,
            ],

            self::ChefEquipe => [
                Permission::PlanningPropose,
                Permission::TimesheetControl,
                Permission::TimesheetOwn,
                Permission::AvailabilityViewPerimeter,
                Permission::AbsenceOwnView,
            ],

            self::Ouvrier => [
                Permission::TimesheetOwn,
                Permission::AbsenceOwnView,
            ],
        };
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * La double authentification est obligatoire pour les rôles qui accèdent au
     * panneau d'administration.
     */
    public function requiresMultiFactorAuthentication(): bool
    {
        return in_array($this, [self::Admin, self::BureauDirection], true);
    }

    public function canAccessAdministrationPanel(): bool
    {
        return in_array($this, [self::Admin, self::BureauDirection], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
