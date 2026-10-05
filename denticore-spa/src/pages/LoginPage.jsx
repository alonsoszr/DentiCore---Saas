import { useEffect, useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams } from 'react-router-dom'
import { z } from 'zod'
import { fieldErrors, generalError } from '../api/errors'
import { homePathFor, loginPathFor, PLATFORM_LOGIN_PATH } from '../auth/paths'
import { useAuth } from '../auth/useAuth'
import { usePublicClinic } from '../auth/usePublicClinic'
import { Alert } from '../components/Alert'
import { AuthLayout } from '../components/auth/AuthLayout'
import { ClinicHeader } from '../components/auth/ClinicHeader'
import { PasswordField } from '../components/auth/PasswordField'
import { Field } from '../components/Field'
import { MailIcon, Spinner } from '../ui/icons'

// Validación en cliente (ficha auth-login › Formulario). La contraseña solo se limita a
// 1–128 caracteres: la política de 10 caracteres es para crear contraseñas.
const loginSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Ingresa tu correo electrónico.')
    .pipe(z.email('Ingresa un correo electrónico válido.')),
  password: z.string().min(1, 'Ingresa tu contraseña.').max(128, 'La contraseña no puede superar 128 caracteres.'),
})

const DEFAULT_RETRY_SECONDS = 60

/**
 * Inicio de sesión (CUS-06; fichas auth-login A1 y auth-login-platform A2). En
 * /c/:slug/login el código de la clínica sale de la URL (DD-29); en /login ingresa el
 * Súper Administrador, sin código.
 */
export function LoginPage() {
  const { slug } = useParams()
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const clinic = usePublicClinic(slug)
  const [form, setForm] = useState({ email: '', password: '' })
  const [clientErrors, setClientErrors] = useState({})
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [lockSeconds, setLockSeconds] = useState(null)

  const status = error?.response?.status
  const lockedMinutes = lockSeconds ? Math.max(1, Math.ceil(lockSeconds / 60)) : null

  // 401: el foco vuelve a la contraseña, que se vació (RF-033).
  useEffect(() => {
    if (status === 401 && !submitting) {
      document.getElementById('password')?.focus()
    }
  }, [error, status, submitting])

  // 429 (RF-035): "Ingresar" queda deshabilitado durante Retry-After; luego se quita el aviso.
  useEffect(() => {
    if (!lockSeconds) return undefined
    const timer = setTimeout(() => {
      setLockSeconds(null)
      setError((current) => (current?.response?.status === 429 ? null : current))
    }, lockSeconds * 1000)
    return () => clearTimeout(timer)
  }, [lockSeconds])

  if (user) {
    return <Navigate to={homePathFor(user)} replace />
  }

  const variant = slug ? 'clinic' : 'platform'

  const handleChange = (event) => {
    const { name, value } = event.target
    setForm({ ...form, [name]: value })
    setClientErrors({ ...clientErrors, [name]: undefined })
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    if (submitting || lockedMinutes) return

    const parsed = loginSchema.safeParse(form)
    if (!parsed.success) {
      const errors = {}
      for (const issue of parsed.error.issues) {
        errors[issue.path[0]] ??= issue.message
      }
      setClientErrors(errors)
      document.getElementById(errors.email ? 'email' : 'password')?.focus()
      return
    }

    setSubmitting(true)
    setError(null)
    try {
      const { user: loggedUser } = await login({ tenantSlug: slug, ...parsed.data })
      const from = location.state?.from?.pathname
      navigate(from ?? homePathFor(loggedUser), { replace: true })
    } catch (err) {
      setError(err)
      if (err?.response?.status === 401) {
        setForm((current) => ({ ...current, password: '' }))
      }
      if (err?.response?.status === 429) {
        setLockSeconds(retryAfterSeconds(err.response))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const apiErrors = fieldErrors(error)
  const errors = {
    email: clientErrors.email ?? apiErrors.email,
    password: clientErrors.password ?? apiErrors.password,
  }

  return (
    <AuthLayout variant={variant} clinicName={clinic.name} footer={<LoginFooter variant={variant} />}>
      {variant === 'clinic' ? <ClinicHeader name={clinic.name} /> : <PlatformHeader />}

      <h1 className="text-headline-lg-mobile sm:text-headline-lg">Inicia sesión</h1>
      <p className="mt-1.5 mb-6 text-body-md text-on-surface-variant">Accede con tu correo y contraseña.</p>

      <StatusAlert error={error} status={status} lockedMinutes={lockedMinutes} />

      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        <Field
          label="Correo electrónico"
          name="email"
          type="email"
          size="lg"
          value={form.email}
          onChange={handleChange}
          error={errors.email}
          placeholder="nombre@correo.com"
          autoComplete="username"
          inputMode="email"
          autoCapitalize="none"
          spellCheck={false}
          disabled={submitting}
          required
          endAdornment={<MailIcon />}
        />
        <div className="flex flex-col gap-2">
          <PasswordField
            label="Contraseña"
            name="password"
            size="lg"
            value={form.password}
            onChange={handleChange}
            error={errors.password}
            autoComplete="current-password"
            disabled={submitting}
            required
          />
          {/* A2 no muestra el enlace hasta definir la ruta de plataforma (PEND-01). */}
          {variant === 'clinic' && (
            <Link to={`/c/${slug}/restablecer`} className="self-end text-label-md font-semibold text-secondary">
              ¿Olvidaste tu contraseña?
            </Link>
          )}
        </div>

        <button type="submit" className="btn mt-2 min-h-12 w-full" disabled={submitting || Boolean(lockedMinutes)}>
          {submitting ? (
            <>
              <Spinner />
              Ingresando…
            </>
          ) : (
            'Ingresar'
          )}
        </button>
      </form>

      {variant === 'platform' && <ClinicCodeForm />}
    </AuthLayout>
  )
}

/** Alerta sobre el formulario según la respuesta de la API. */
function StatusAlert({ error, status, lockedMinutes }) {
  if (status === 401) {
    // Mismo mensaje para cualquier dato incorrecto y para la cuenta bloqueada (RF-033, CUS-06 FE-2).
    return <Alert tone="error" title="Credenciales inválidas" className="mb-5" />
  }
  if (status === 429 && lockedMinutes) {
    const unit = lockedMinutes === 1 ? 'minuto' : 'minutos'
    return (
      <Alert tone="warning" className="mb-5">
        Demasiados intentos. Vuelve a intentarlo en {lockedMinutes} {unit}.
      </Alert>
    )
  }
  if (status === 429) return null

  const message = generalError(error)
  return message ? (
    <Alert tone="error" className="mb-5">
      {message}
    </Alert>
  ) : null
}

/** Encabezado del formulario en /login (ficha auth-login-platform). */
function PlatformHeader() {
  return (
    <div className="mb-7 flex items-center justify-between gap-3 border-b border-outline-variant pb-6">
      <p className="m-0 min-w-0 text-title-md font-bold text-on-surface">DentiCore · Administración de la plataforma</p>
      <span className="shrink-0 rounded-full bg-primary-container px-2.5 py-1 text-label-sm text-on-primary-container">
        Acceso restringido
      </span>
    </div>
  )
}

function LoginFooter({ variant }) {
  if (variant === 'platform') {
    return (
      <>
        <p className="m-0">¿Problemas para ingresar? Contacta al equipo de DentiCore.</p>
        <p className="mt-1.5 mb-0">Acceso exclusivo para administradores de la plataforma.</p>
      </>
    )
  }
  return (
    <>
      <p className="m-0">¿Problemas para ingresar? Contacta al administrador de tu clínica.</p>
      <p className="mt-1.5 mb-0">Las cuentas del personal se crean solo por invitación.</p>
      <p className="mt-4 mb-0">
        ¿No es tu clínica?{' '}
        <Link to={PLATFORM_LOGIN_PATH} className="font-semibold text-secondary">
          Cambiar de clínica
        </Link>
      </p>
    </>
  )
}

/** Lleva al login de una clínica (/c/:slug/login) a partir de su código de acceso. */
function ClinicCodeForm() {
  const navigate = useNavigate()
  const [code, setCode] = useState('')

  const handleSubmit = (event) => {
    event.preventDefault()
    const slug = code.trim().toLowerCase()
    if (slug) {
      navigate(loginPathFor(slug))
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="mt-8 flex flex-col gap-4 border-t border-outline-variant pt-6">
      <Field
        label="¿Trabajas en una clínica? Escribe su código de acceso"
        name="clinicCode"
        size="lg"
        value={code}
        onChange={(event) => setCode(event.target.value)}
        autoComplete="off"
        autoCapitalize="none"
        spellCheck={false}
      />
      <button type="submit" className="btn btn-secondary min-h-12 w-full">
        Ir al acceso de la clínica
      </button>
    </form>
  )
}

/** Segundos de espera de un 429: Retry-After en segundos o como fecha HTTP. */
function retryAfterSeconds(response) {
  const headers = response?.headers ?? {}
  const value = typeof headers.get === 'function' ? headers.get('retry-after') : headers['retry-after']
  const seconds = Number(value)
  if (Number.isFinite(seconds) && seconds > 0) return seconds
  const date = Date.parse(value)
  if (!Number.isNaN(date)) return Math.max(1, Math.ceil((date - Date.now()) / 1000))
  return DEFAULT_RETRY_SECONDS
}
