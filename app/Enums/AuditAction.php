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
            self::SelfApprovalRecorded => 'Auto-validation motivée',
            self::EmergencyLockdown => 'Verrouillage d’urgence',
        };
    }
}
