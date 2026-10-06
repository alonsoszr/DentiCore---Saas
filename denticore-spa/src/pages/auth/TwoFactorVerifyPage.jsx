import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { formatCountdown, retryAfterSeconds } from '../../api/retryAfter'
import { homePathFor, loginPathFor } from '../../auth/paths'
import { clearToken } from '../../auth/token'
import { useAuth } from '../../auth/useAuth'
import { usePublicClinic } from '../../auth/usePublicClinic'
import { Alert } from '../../components/Alert'
import { AuthLayout } from '../../components/auth/AuthLayout'
import { ClinicHeader } from '../../components/auth/ClinicHeader'
import { OtpInput } from '../../components/auth/OtpInput'
import { Field } from '../../components/Field'
import { Spinner } from '../../ui/icons'
import { maskEmail } from './authHelpers'

/**
 * Verificación en dos pasos (A3; CUS-07; RF-037, RF-038). Solo con un token `2fa:pending`;
 * sin él vuelve al login. /c/:slug/login/2fa o /login/2fa (PEND-02).
 */
export function TwoFactorVerifyPage() {
  const { slug } = useParams()
  const { twoFactor, pendingEmail, completeSession, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const clinic = usePublicClinic(slug)
  const [code, setCode] = useState('')
  const [recoveryMode, setRecoveryMode] = useState(false)
  const [recoveryCode, setRecoveryCode] = useState('')
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [lockSeconds, setLockSeconds] = useState(0)

  useEffect(() => {
    if (lockSeconds <= 0) return undefined
    const timer = setTimeout(() => setLockSeconds((value) => value - 1), 1000)
    return () => clearTimeout(timer)
  }, [lockSeconds])

  if (twoFactor !== 'pending') {
    return <Navigate to={loginPathFor(slug)} replace />
  }

  const variant = slug ? 'clinic' : 'platform'
  const locked = lockSeconds > 0

  const verify = async (body) => {
    if (submitting || locked) return
    setSubmitting(true)
    setError(null)
    try {
      const { data } = await apiClient.post('/auth/2fa/verify', body)
      completeSession(data)
      navigate(location.state?.from?.pathname ?? homePathFor(data.user), { replace: true })
    } catch (err) {
      const status = err?.response?.status
      if (status === 401) {
        clearToken()
        navigate(loginPathFor(slug), { replace: true, state: { notice: 'Tu sesión expiró. Inicia sesión de nuevo.' } })
        return
      }
      if (status === 429) setLockSeconds(retryAfterSeconds(err.response))
      setError(err)
      setCode('')
      document.querySelector('.otp-digit')?.focus()
    } finally {
      setSubmitting(false)
    }
  }

  const backToLogin = async () => {
    await logout().catch(() => {})
    navigate(loginPathFor(slug), { replace: true })
  }

  const status = error?.response?.status
  const errors = fieldErrors(error)

  return (
    <AuthLayout variant={variant} clinicName={clinic.name}>
      {variant === 'clinic' && <ClinicHeader name={clinic.name} />}

      <p className="m-0 text-label-md text-on-surface-variant">Paso 2 de 2 · Verificación de identidad</p>
      <h1 className="text-headline-lg-mobile sm:text-headline-lg">Verificación en dos pasos</h1>
      <p className="mt-1.5 mb-2 text-body-md text-on-surface-variant">
        {recoveryMode
          ? 'Ingresa uno de los códigos de recuperación que guardaste al configurar la verificación. Cada código se usa una sola vez.'
          : 'Ingresa el código de 6 dígitos de tu aplicación de autenticación.'}
      </p>
      {pendingEmail && (
        <p className="mt-0 mb-6 text-body-md text-on-surface-variant">Ingresaste como {maskEmail(pendingEmail)}</p>
      )}

      {status === 422 && (
        <Alert tone="error" title="Código incorrecto." className="mb-5">
          Revisa que la hora de tu teléfono sea correcta o espera el siguiente código.
        </Alert>
      )}
      {status === 429 && locked && (
        <Alert tone="error" className="mb-5">
          Demasiados intentos. Espera {formatCountdown(lockSeconds)} o contacta al administrador de tu clínica.
        </Alert>
      )}
      {status && status !== 422 && status !== 429 && (
        <Alert tone="error" className="mb-5">
          {generalError(error)}
        </Alert>
      )}

      {recoveryMode ? (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            verify({ recovery_code: recoveryCode.trim() })
          }}
          noValidate
          className="flex flex-col gap-4"
        >
          <Field
            label="Código de recuperación"
            name="recovery_code"
            size="lg"
            className="mono"
            placeholder="XXXX-XXXX"
            autoComplete="off"
            value={recoveryCode}
            onChange={(event) => setRecoveryCode(event.target.value)}
            error={errors.recovery_code}
            disabled={locked}
          />
          <button type="submit" className="btn min-h-12 w-full" disabled={submitting || locked || !recoveryCode}>
            {submitting ? <Spinner /> : null}
            Verificar código de recuperación
          </button>
          <button type="button" className="btn-link" onClick={() => setRecoveryMode(false)}>
            Volver a ingresar el código de 6 dígitos
          </button>
        </form>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            if (code.length === 6) verify({ code })
          }}
          noValidate
          className="flex flex-col gap-4"
        >
          <OtpInput
            value={code}
            onChange={setCode}
            onComplete={(full) => verify({ code: full })}
            disabled={locked || submitting}
            invalid={status === 422}
          />
          <button type="submit" className="btn min-h-12 w-full" disabled={submitting || locked || code.length !== 6}>
            {submitting ? <Spinner /> : null}
            Verificar e ingresar
          </button>
          <button
            type="button"
            className="btn-link"
            onClick={() => {
              setRecoveryMode(true)
              setError(null)
            }}
          >
            ¿No tienes acceso a tu app? Usar código de recuperación
          </button>
        </form>
      )}

      <p className="mt-6 mb-0 text-center">
        <Link
          to={loginPathFor(slug)}
          className="font-semibold text-secondary"
          onClick={(event) => {
            event.preventDefault()
            backToLogin()
          }}
        >
          Volver al inicio de sesión
        </Link>
      </p>
    </AuthLayout>
  )
}
