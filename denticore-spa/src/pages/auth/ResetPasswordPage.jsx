import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
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

/**
 * Restablecer contraseña (A5; CUS-09; RF-039, RF-040). No hay endpoint para consultar el
 * token: la pantalla no conoce al usuario y las reglas de servidor se marcan al guardar.
 */
export function ResetPasswordPage() {
  const { slug, token } = useParams()
  const clinic = usePublicClinic(slug)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState(null)
  const [done, setDone] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const status = error?.response?.status
  const messages = status === 422 ? (error.response.data?.errors?.password ?? []) : []
  const mismatch = confirmation !== '' && confirmation !== password
  const canSubmit = lengthState(password) === 'ok' && password === confirmation && !submitting

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (!canSubmit) return
    setSubmitting(true)
    setError(null)
    try {
      await apiClient.post('/auth/password/reset', { token, password, password_confirmation: confirmation })
      setDone(true)
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  let content
  if (status === 404) {
    content = (
      <>
        <h1 className="text-headline-lg-mobile sm:text-headline-lg">Este enlace ya no es válido</h1>
        <p className="text-body-md text-on-surface-variant">
          Los enlaces vencen a los 60 minutos y se usan una sola vez.
        </p>
        <Link to={`/c/${slug}/restablecer`} className="btn w-full">
          Solicitar un enlace nuevo
        </Link>
      </>
    )
  } else if (done) {
    content = (
      <>
        <Alert tone="success" className="mb-5">
          Tu contraseña se actualizó. Por seguridad, cerramos tus sesiones abiertas.
        </Alert>
        <Link to={loginPathFor(slug)} className="btn w-full">
          Ir al inicio de sesión
        </Link>
      </>
    )
  } else {
    content = (
      <>
        <h1 className="text-headline-lg-mobile sm:text-headline-lg">Restablecer contraseña</h1>
        <p className="mt-1.5 mb-2 text-body-md text-on-surface-variant">
          Crea una nueva contraseña para acceder a tu cuenta.
        </p>
        <p className="mt-0 mb-6 text-body-md text-on-surface-variant">
          Al guardar se cerrarán todas tus sesiones abiertas.
        </p>
        {status && status !== 422 && (
          <Alert tone="error" className="mb-5">
            {generalError(error)}
          </Alert>
        )}
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
          <PasswordField
            label="Nueva contraseña"
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
              { label: 'No contiene tu correo ni tu nombre', state: serverState(messages, /correo ni tu nombre/) },
              { label: 'No es igual a tus últimas 5 contraseñas', state: serverState(messages, /5 últimas/) },
            ]}
          />
          <button type="submit" className="btn min-h-12 w-full" disabled={!canSubmit}>
            {submitting ? <Spinner /> : null}
            Guardar contraseña
          </button>
        </form>
        <p className="mt-4 mb-0 text-center">
          <Link to={loginPathFor(slug)} className="font-semibold text-secondary">
            Volver al inicio de sesión
          </Link>
        </p>
      </>
    )
  }

  return (
    <AuthLayout variant="clinic" clinicName={clinic.name}>
      <ClinicHeader name={clinic.name} />
      {content}
    </AuthLayout>
  )
}
