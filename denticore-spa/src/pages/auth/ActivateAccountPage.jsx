import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { loginPathFor } from '../../auth/paths'
import { usePublicClinic } from '../../auth/usePublicClinic'
import { Alert } from '../../components/Alert'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { ClinicHeader } from '../../components/auth/ClinicHeader'
import { PasswordField } from '../../components/auth/PasswordField'
import { PasswordRequirements } from '../../components/auth/PasswordRequirements'
import { lengthState, serverState } from '../../components/auth/passwordRules'
import { Spinner } from '../../ui/icons'
import { containsIdentity } from './authHelpers'

const ROLE_LABELS = {
  clinic_admin: 'Administrador de Clínica',
  dentist: 'Odontólogo',
  receptionist: 'Recepcionista',
  patient: 'Paciente',
}

/**
 * Activar cuenta por invitación (A6; CUS-01, CUS-11; RF-016, RF-040, DD-22). Tras activar,
 * la API responde 204 y la SPA lleva al login (PEND-05).
 */
export function ActivateAccountPage() {
  const { slug, token } = useParams()
  const navigate = useNavigate()
  const clinic = usePublicClinic(slug)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [accepted, setAccepted] = useState(false)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const invitationQuery = useQuery({
    queryKey: ['invitation', token],
    queryFn: async () => (await apiClient.get(`/auth/invitations/${token}`)).data.data,
    retry: false,
  })

  const invitation = invitationQuery.data
  const status = error?.response?.status
  const messages = status === 422 ? (error.response.data?.errors?.password ?? []) : []
  const identityState = containsIdentity(password, invitation)
    ? 'failed'
    : password.length > 0 && !messages.some((message) => /correo ni tu nombre/.test(message))
      ? 'ok'
      : serverState(messages, /correo ni tu nombre/)
  const mismatch = confirmation !== '' && confirmation !== password
  const canSubmit =
    lengthState(password) === 'ok' && identityState !== 'failed' && password === confirmation && accepted && !submitting

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (!canSubmit) return
    setSubmitting(true)
    setError(null)
    try {
      await apiClient.post(`/auth/invitations/${token}/accept`, { password, password_confirmation: confirmation })
      navigate(loginPathFor(slug), { replace: true, state: { notice: 'Tu cuenta está activa. Inicia sesión.' } })
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  const invalid = invitationQuery.error?.response?.status === 404 || status === 404

  return (
    <AuthLayout variant="clinic" clinicName={invitation?.clinic?.name ?? clinic.name}>
      <ClinicHeader name={invitation?.clinic?.name ?? clinic.name} />

      {invalid && (
        <>
          <h1 className="text-headline-lg-mobile sm:text-headline-lg">Esta invitación ya no es válida</h1>
          <p className="text-body-md text-on-surface-variant">
            Las invitaciones vencen a las 72 horas y se usan una sola vez. Pide al administrador de tu clínica que te
            envíe una nueva.
          </p>
          <Link to={loginPathFor(slug)} className="font-semibold text-secondary">
            Ir al inicio de sesión
          </Link>
        </>
      )}

      {!invalid && invitationQuery.isLoading && <p className="empty">Cargando…</p>}
      {!invalid && invitationQuery.isError && <Alert tone="error">{generalError(invitationQuery.error)}</Alert>}

      {!invalid && invitation && (
        <>
          <h1 className="text-headline-lg-mobile sm:text-headline-lg">Te invitaron a {invitation.clinic?.name}</h1>
          <p className="mt-1.5 mb-5 text-body-md text-on-surface-variant">
            Completa tus datos de acceso para ingresar a la clínica.
          </p>
          <div className="card">
            <p className="m-0 text-title-md">{invitation.name}</p>
            <p className="m-0 muted">{invitation.email}</p>
            <span className="badge mt-2">{ROLE_LABELS[invitation.role] ?? invitation.role}</span>
          </div>
          {invitation.role === 'clinic_admin' && (
            <Alert tone="info" className="mb-5">
              En el siguiente paso configurarás la verificación en dos pasos.
            </Alert>
          )}
          {status && status !== 422 && status !== 404 && (
            <Alert tone="error" className="mb-5">
              {generalError(error)}
            </Alert>
          )}
          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
            <PasswordField
              label="Crea tu contraseña"
              name="password"
              size="lg"
              autoComplete="new-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              error={messages[0]}
            />
            <PasswordField
              label="Confirmar contraseña"
              name="password_confirmation"
              size="lg"
              autoComplete="new-password"
              value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)}
              error={mismatch ? 'Las contraseñas no coinciden' : undefined}
            />
            <PasswordRequirements
              items={[
                { label: 'Entre 10 y 128 caracteres', state: lengthState(password) },
                { label: 'No es una contraseña común', state: serverState(messages, /común/) },
                { label: 'No contiene tu correo ni tu nombre', state: identityState },
              ]}
            />
            <label className="checkbox">
              <input type="checkbox" checked={accepted} onChange={(event) => setAccepted(event.target.checked)} />
              Acepto los términos de uso y la política de privacidad
            </label>
            <button type="submit" className="btn min-h-12 w-full" disabled={!canSubmit}>
              {submitting ? <Spinner /> : null}
              Activar cuenta
            </button>
          </form>
        </>
      )}
    </AuthLayout>
  )
}
