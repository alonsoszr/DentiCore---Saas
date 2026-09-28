<?php

namespace App\Support\Audit;

/**
 * Catálogo de eventos auditables (SDD §5.14, RN-67). Cada milestone registra los eventos que
 * sus Services producen (DoD-05).
 */
enum AuditEvent: string
{
    // Sesión
    case AuthLoginOk = 'auth.login_ok';
    case AuthLoginFailed = 'auth.login_failed';
    case AuthLocked = 'auth.locked';
    case AuthLogout = 'auth.logout';
    case Auth2faConfigured = 'auth.2fa_configured';
    case Auth2faReset = 'auth.2fa_reset';
    case AuthPasswordChanged = 'auth.password_changed';
    case AuthSessionRevoked = 'auth.session_revoked';

    // Plataforma
    case TenantCreated = 'tenant.created';
    case TenantSuspended = 'tenant.suspended';
    case TenantReactivated = 'tenant.reactivated';
    case TenantCancelled = 'tenant.cancelled';
    case TenantPurged = 'tenant.purged';
    case TenantPlanChanged = 'tenant.plan_changed';
    case TenantKeyRotated = 'tenant.key_rotated';
    case ModelRegistered = 'model.registered';
    case ModelActivated = 'model.activated';

    // Usuarios y permisos
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserUnlocked = 'user.unlocked';
    case UserDataOfficerChanged = 'user.data_officer_changed';

    // Paciente
    case PatientCreated = 'patient.created';
    case PatientIdentityUpdated = 'patient.identity_updated';
    case PatientDeceasedMarked = 'patient.deceased_marked';
    case PatientMerged = 'patient.merged';
    case PatientBlocked = 'patient.blocked';
    case PatientArchived = 'patient.archived';
    case PatientDeleted = 'patient.deleted';
    case RepresentativeCreated = 'representative.created';
    case RepresentativeEnded = 'representative.ended';
    case ConsentGranted = 'consent.granted';
    case ConsentPurposeRevoked = 'consent.purpose_revoked';
    case InformedConsentSigned = 'informed_consent.signed';
    case InformedConsentRevoked = 'informed_consent.revoked';
    case AttachmentAdded = 'attachment.added';
    case AttachmentVoided = 'attachment.voided';

    // Clínico
    case ClinicalRecordViewed = 'clinical_record.viewed';
    case AttentionOpened = 'attention.opened';
    case AttentionClosed = 'attention.closed';
    case AttentionAutoClosed = 'attention.auto_closed';
    case NoteSaved = 'note.saved';
    case DiagnosisAdded = 'diagnosis.added';
    case AddendumAdded = 'addendum.added';
    case OdontogramEntryAdded = 'odontogram.entry_added';
    case OdontogramEntryCorrected = 'odontogram.entry_corrected';
    case FindingNoTreat = 'finding.no_treat';
    case AiSuggestionRequested = 'ai.suggestion_requested';
    case AiSuggestionDecided = 'ai.suggestion_decided';
    case RiskVariablesCaptured = 'risk.variables_captured';
    case RiskPredicted = 'risk.predicted';
    case RiskAlertAcknowledged = 'risk.alert_acknowledged';
    case FollowupRecorded = 'followup.recorded';

    // Comercial
    case PlanCreated = 'plan.created';
    case PlanStatusChanged = 'plan.status_changed';
    case BudgetIssued = 'budget.issued';
    case BudgetDecided = 'budget.decided';
    case BudgetExpired = 'budget.expired';
    case BudgetShared = 'budget.shared';
    case ProcedurePerformed = 'procedure.performed';
    case PaymentRegistered = 'payment.registered';
    case PaymentVoided = 'payment.voided';

    // Agenda
    case AppointmentCreated = 'appointment.created';
    case AppointmentRescheduled = 'appointment.rescheduled';
    case AppointmentCancelled = 'appointment.cancelled';
    case AppointmentConfirmed = 'appointment.confirmed';
    case AppointmentCheckedIn = 'appointment.checked_in';
    case AppointmentNoShow = 'appointment.no_show';

    // Cumplimiento
    case DocumentDownloaded = 'document.downloaded';
    case ExportRequested = 'export.requested';
    case ExportDownloaded = 'export.downloaded';
    case ArcoReceived = 'arco.received';
    case ArcoResolved = 'arco.resolved';
    case IncidentRegistered = 'incident.registered';
    case IncidentNotified = 'incident.notified';
    case AuditExported = 'audit.exported';
    case ImportConfirmed = 'import.confirmed';
}
