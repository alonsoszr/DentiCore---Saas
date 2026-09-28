<?php

namespace App\Support\Tokens;

/**
 * Propósitos de `one_time_tokens` (SDD §2.4, DI-15).
 */
enum TokenPurpose: string
{
    case Invitation = 'invitacion';
    case PasswordReset = 'restablecimiento';
    case AppointmentConfirmation = 'confirmacion_cita';
    case BudgetOtp = 'otp_presupuesto';
    case SharedBudget = 'presupuesto_compartido';
    case Survey = 'encuesta';
    case EmailVerification = 'verificacion_correo';

    /**
     * El enlace de presupuesto compartido es reutilizable hasta su vencimiento o revocación:
     * no fija `used_at` (SDD §2.4, RF-131).
     */
    public function isReusable(): bool
    {
        return $this === self::SharedBudget;
    }
}
