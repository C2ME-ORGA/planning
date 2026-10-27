<?php

namespace App\Enums;

enum AuditAction: string
{
    case LoginSucceeded = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case PasswordResetRequested = 'auth.password_reset_requested';
    case PasswordResetCompleted = 'auth.password_reset_completed';

    case AccountCreated = 'account.created';
    case ActivationSent = 'account.activation_sent';
    case AccountActivated = 'account.activated';
    case EmailChanged = 'account.email_changed';
    case RoleChanged = 'account.role_changed';
    case PasswordChanged = 'account.password_changed';
    case AccountDeactivated = 'account.deactivated';
    case AccountReactivated = 'account.reactivated';
    case SessionsRevoked = 'account.sessions_revoked';
    case MfaEnabled = 'account.mfa_enabled';
    case MfaDisabled = 'account.mfa_disabled';
    case MfaReset = 'account.mfa_reset';
    case BootstrapBureauCreated = 'account.bootstrap_bureau_created';

    case PermissionExceptionGranted = 'permission_exception.granted';
    case PermissionExceptionRevoked = 'permission_exception.revoked';

    case EmployeeCreated = 'employee.created';
    case EmployeeUpdated = 'employee.updated';
    case EmployeeAccountLinked = 'employee.account_linked';
    case EmployeeAccountUnlinked = 'employee.account_unlinked';
    case EmploymentPeriodAdded = 'employment_period.added';
    case EmployeeRehired = 'employee.rehired';
    case EmployeeExited = 'employee.exited';

    case TeamCreated = 'team.created';
    case TeamUpdated = 'team.updated';
    case TeamAssignmentCreated = 'team_assignment.created';
    case TeamAssignmentClosed = 'team_assignment.closed';
    case TeamAssignmentCancelled = 'team_assignment.cancelled';
    case TeamLeaderAssigned = 'team_leader.assigned';
    case TeamLeaderReassigned = 'team_leader.reassigned';
    case TeamLeaderClosed = 'team_leader.closed';
    case TeamLeaderCancelled = 'team_leader.cancelled';

    case ClientCreated = 'client.created';
    case ClientUpdated = 'client.updated';
    case ClientArchived = 'client.archived';
    case ClientRestored = 'client.restored';
    case SiteCreated = 'site.created';
    case SiteUpdated = 'site.updated';
    case SiteArchived = 'site.archived';
    case SiteStatusChanged = 'site.status_changed';
    case SiteReopened = 'site.reopened';

    case SelfApprovalRecorded = 'approval.self_approval_recorded';

    case EmergencyLockdown = 'system.emergency_lockdown';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Connexion réussie',
            self::LoginFailed => 'Échec de connexion',
            self::Logout => 'Déconnexion',
            self::PasswordResetRequested => 'Réinitialisation demandée',
            self::PasswordResetCompleted => 'Mot de passe réinitialisé',
            self::AccountCreated => 'Compte créé',
            self::ActivationSent => 'Activation envoyée',
            self::AccountActivated => 'Compte activé',
            self::EmailChanged => 'Adresse e-mail modifiée',
            self::RoleChanged => 'Rôle modifié',
            self::PasswordChanged => 'Mot de passe modifié',
            self::AccountDeactivated => 'Compte désactivé',
            self::AccountReactivated => 'Compte réactivé',
            self::SessionsRevoked => 'Sessions révoquées',
            self::MfaEnabled => 'Double authentification activée',
            self::MfaDisabled => 'Double authentification désactivée',
            self::MfaReset => 'Double authentification réinitialisée',
            self::BootstrapBureauCreated => 'Bootstrap Bureau / Direction',
            self::PermissionExceptionGranted => 'Exception de permission accordée',
            self::PermissionExceptionRevoked => 'Exception de permission révoquée',
            self::EmployeeCreated => 'Salarié créé',
            self::EmployeeUpdated => 'Salarié modifié',
            self::EmployeeAccountLinked => 'Compte lié au salarié',
            self::EmployeeAccountUnlinked => 'Compte délié du salarié',
            self::EmploymentPeriodAdded => 'Période d’emploi ajoutée',
            self::EmployeeRehired => 'Salarié réembauché',
            self::EmployeeExited => 'Sortie du salarié enregistrée',
            self::TeamCreated => 'Équipe créée',
            self::TeamUpdated => 'Équipe modifiée',
            self::TeamAssignmentCreated => 'Affectation d’équipe créée',
            self::TeamAssignmentClosed => 'Affectation d’équipe clôturée',
            self::TeamAssignmentCancelled => 'Affectation d’équipe annulée',
            self::TeamLeaderAssigned => 'Responsable d’équipe désigné',
            self::TeamLeaderReassigned => 'Responsable d’équipe réaffecté',
            self::TeamLeaderClosed => 'Responsabilité d’équipe clôturée',
            self::TeamLeaderCancelled => 'Responsabilité d’équipe annulée',
            self::ClientCreated => 'Client créé',
            self::ClientUpdated => 'Client modifié',
            self::ClientArchived => 'Client archivé',
            self::ClientRestored => 'Client restauré',
            self::SiteCreated => 'Chantier créé',
            self::SiteUpdated => 'Chantier modifié',
            self::SiteArchived => 'Chantier archivé',
            self::SiteStatusChanged => 'Statut du chantier modifié',
            self::SiteReopened => 'Chantier réouvert',
            self::SelfApprovalRecorded => 'Auto-validation motivée',
            self::EmergencyLockdown => 'Verrouillage d’urgence',
        };
    }
}
