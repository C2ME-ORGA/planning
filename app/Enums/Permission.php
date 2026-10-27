<?php

namespace App\Enums;

/**
 * Capacités vérifiées côté serveur.
 *
 * Les capacités des lots 2 à 4 sont appliquées par les policies et services
 * correspondants (comptes, audit, salariés, équipes, clients et chantiers).
 * Les capacités des lots ultérieurs restent déclarées ici jusqu'à ce que leurs
 * policies soient implémentées.
 */
enum Permission: string
{
    // Comptes et rôles (lot 2).
    case AccountsView = 'accounts.view';
    case AccountsCreate = 'accounts.create';
    case AccountsUpdate = 'accounts.update';
    case AccountsDeactivate = 'accounts.deactivate';
    case AccountsReactivate = 'accounts.reactivate';
    case AccountsResendActivation = 'accounts.resend-activation';
    case RolesAssign = 'roles.assign';
    case AuditView = 'audit.view';
    case PermissionExceptionsManage = 'permission-exceptions.manage';

    // Salariés et équipes (lot 3).
    case EmployeesView = 'employees.view';
    case EmployeesManage = 'employees.manage';
    case TeamsView = 'teams.view';
    case TeamsManage = 'teams.manage';

    // Clients et chantiers (lot 4).
    case ClientsView = 'clients.view';
    case ClientsManage = 'clients.manage';
    case ClientsRestore = 'clients.restore';
    case SitesView = 'sites.view';
    case SitesManage = 'sites.manage';
    case SitesReopen = 'sites.reopen';

    // Capacités métier (appliquées par les lots ultérieurs).
    case PlanningPropose = 'planning.propose';
    case PlanningManage = 'planning.manage';
    case TimesheetOwn = 'timesheet.own';
    case TimesheetControl = 'timesheet.control';
    case TimesheetValidate = 'timesheet.validate';
    case WorkflowCorrect = 'workflow.correct';
    case WorkflowReopen = 'workflow.reopen';
    case AvailabilityViewPerimeter = 'availability.view-perimeter';
    case AbsenceOwnView = 'absence.own-view';
    case AbsenceManage = 'absence.manage';

    public function label(): string
    {
        return match ($this) {
            self::AccountsView => 'Consulter les comptes',
            self::AccountsCreate => 'Créer un compte',
            self::AccountsUpdate => 'Modifier un compte',
            self::AccountsDeactivate => 'Désactiver un compte',
            self::AccountsReactivate => 'Réactiver un compte',
            self::AccountsResendActivation => 'Renvoyer une activation',
            self::RolesAssign => 'Attribuer un rôle',
            self::AuditView => 'Consulter le journal d’audit',
            self::PermissionExceptionsManage => 'Gérer les exceptions de permission',
            self::EmployeesView => 'Consulter les salariés et leurs historiques',
            self::EmployeesManage => 'Gérer les salariés et leurs périodes d’emploi',
            self::TeamsView => 'Consulter les équipes et leurs historiques',
            self::TeamsManage => 'Gérer les équipes, affectations et responsables',
            self::ClientsView => 'Consulter les clients',
            self::ClientsManage => 'Gérer les clients',
            self::ClientsRestore => 'Restaurer un client archivé',
            self::SitesView => 'Consulter les chantiers',
            self::SitesManage => 'Gérer les chantiers',
            self::SitesReopen => 'Réouvrir un chantier terminé ou annulé',
            self::PlanningPropose => 'Proposer une modification de planning',
            self::PlanningManage => 'Modifier le planning',
            self::TimesheetOwn => 'Gérer ses propres relevés',
            self::TimesheetControl => 'Contrôler les relevés de son périmètre',
            self::TimesheetValidate => 'Valider définitivement un relevé',
            self::WorkflowCorrect => 'Corriger un relevé',
            self::WorkflowReopen => 'Réouvrir un relevé validé',
            self::AvailabilityViewPerimeter => 'Voir la disponibilité de son périmètre',
            self::AbsenceOwnView => 'Consulter ses propres absences',
            self::AbsenceManage => 'Gérer les absences administratives',
        };
    }

    /**
     * Une exception temporaire ne doit jamais donner accès aux comptes, aux
     * rôles, à l'audit, ni contourner la validation finale, la correction ou
     * une réouverture. Seules des capacités métier bornées sont éligibles.
     */
    public function isExceptionEligible(): bool
    {
        return in_array($this, [
            self::PlanningPropose,
            self::TimesheetControl,
            self::AvailabilityViewPerimeter,
        ], true);
    }

    /**
     * @return array<int, self>
     */
    public static function exceptionEligible(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permission): bool => $permission->isExceptionEligible(),
        ));
    }
}
