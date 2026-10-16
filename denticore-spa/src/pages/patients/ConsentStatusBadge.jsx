import { StatusBadge } from '../../components/StatusBadge'
import { AlertTriangleIcon, CheckIcon, ClockIcon, XIcon } from '../../ui/icons'

const STATES = {
  vigente: { label: 'Vigente', tone: 'success', icon: CheckIcon },
  sustituido: { label: 'Sustituido', tone: 'neutral', icon: ClockIcon },
  revocado: { label: 'Revocado', tone: 'error', icon: XIcon },
}

/** Estado de un consentimiento (DESIGN.md › Status palette: vigente, sustituido, revocado). */
export function ConsentStatusBadge({ status }) {
  const state = STATES[status] ?? { label: status, tone: 'neutral' }
  return (
    <StatusBadge tone={state.tone} icon={state.icon}>
      {state.label}
    </StatusBadge>
  )
}

/**
 * Consentimiento del paciente en su cabecera: vigente (tertiary), de una versión anterior (aviso)
 * o ausente, que bloquea el registro clínico (RN-10).
 */
export function PatientConsentBadge({ patient }) {
  if (!patient.has_current_consent) {
    return (
      <StatusBadge tone="error" icon={XIcon}>
        Sin consentimiento vigente
      </StatusBadge>
    )
  }
  if (patient.consent_outdated) {
    return (
      <StatusBadge tone="warning" icon={AlertTriangleIcon}>
        Consentimiento de una versión anterior
      </StatusBadge>
    )
  }
  return (
    <StatusBadge tone="success" icon={CheckIcon}>
      Consentimiento vigente
    </StatusBadge>
  )
}
