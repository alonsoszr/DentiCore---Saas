import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { z } from 'zod'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { retryAfterSeconds } from '../../api/retryAfter'
import { loginPathFor } from '../../auth/paths'
import { usePublicClinic } from '../../auth/usePublicClinic'
import { Alert } from '../../components/Alert'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { ClinicHeader } from '../../components/auth/ClinicHeader'
import { Field } from '../../components/Field'
import { MailIcon, Spinner } from '../../ui/icons'

const emailSchema = z.string().trim().pipe(z.email())

/**
 * ¿Olvidaste tu contraseña? (A5; CUS-09; RF-039). La API responde igual exista o no el
 * correo, así que el mensaje de éxito nunca confirma la cuenta.
 */
export function ForgotPasswordPage() {
  const { slug } = useParams()
  const clinic = usePublicClinic(slug)
  const [email, setEmail] = useState('')
  const [clientError, setClientError] = useState(null)
  const [error, setError] = useState(null)
  const [sent, setSent] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async (event) => {
    event.preventDefault()
    const parsed = emailSchema.safeParse(email)
    if (!parsed.success) {
      setClientError('Ingresa un correo válido.')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await apiClient.post('/auth/password/forgot', { tenant_slug: slug, email: parsed.data })
      setSent(true)
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  const status = error?.response?.status
  const waitMinutes = status === 429 ? Math.max(1, Math.ceil(retryAfterSeconds(error.response) / 60)) : null

  return (
    <AuthLayout variant="clinic" clinicName={clinic.name}>
      <ClinicHeader name={clinic.name} />
      <h1 className="text-headline-lg-mobile sm:text-headline-lg">¿Olvidaste tu contraseña?</h1>

      {sent ? (
        <>
          <Alert tone="success" className="my-5">
            Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. El enlace vence en 60
            minutos.
          </Alert>
          <Link to={loginPathFor(slug)} className="btn btn-secondary w-full">
            Volver al inicio de sesión
          </Link>
        </>
      ) : (
        <>
          <p className="mt-1.5 mb-6 text-body-md text-on-surface-variant">
            Ingresa tu correo y te enviaremos un enlace para restablecer tu contraseña.
          </p>
          {waitMinutes && (
            <Alert tone="warning" className="mb-5">
              Demasiados intentos. Vuelve a intentarlo en {waitMinutes} {waitMinutes === 1 ? 'minuto' : 'minutos'}.
            </Alert>
          )}
          {status && status !== 429 && (
            <Alert tone="error" className="mb-5">
              {generalError(error)}
            </Alert>
          )}
          <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
            <Field
              label="Correo electrónico"
              name="email"
              type="email"
              size="lg"
              placeholder="nombre@correo.com"
              autoComplete="username"
              value={email}
              onChange={(event) => {
                setEmail(event.target.value)
                setClientError(null)
              }}
              error={clientError}
              endAdornment={<MailIcon />}
              disabled={submitting}
            />
            <button type="submit" className="btn min-h-12 w-full" disabled={submitting}>
              {submitting ? <Spinner /> : null}
              Enviar enlace
            </button>
          </form>
          <p className="mt-6 mb-0 text-center">
            <Link to={loginPathFor(slug)} className="font-semibold text-secondary">
              Volver al inicio de sesión
            </Link>
          </p>
        </>
      )}
    </AuthLayout>
  )
}
