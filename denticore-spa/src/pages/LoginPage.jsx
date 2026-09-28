import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useParams } from 'react-router-dom'
import { fieldErrors, generalError } from '../api/errors'
import { homePathFor, loginPathFor, PLATFORM_LOGIN_PATH } from '../auth/paths'
import { useAuth } from '../auth/useAuth'
import { Field } from '../components/Field'

/**
 * Inicio de sesión (SDD §1.8, DD-29). En /c/:slug/login el código de la clínica sale de
 * la URL; en /login ingresa el Súper Administrador, sin código.
 */
export function LoginPage() {
  const { slug } = useParams()
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) {
    return <Navigate to={homePathFor(user)} replace />
  }

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      const { user: loggedUser } = await login({ tenantSlug: slug, ...form })
      const from = location.state?.from?.pathname
      navigate(from ?? homePathFor(loggedUser), { replace: true })
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  const errors = fieldErrors(error)
  const message = error?.response?.status === 401 ? 'Credenciales inválidas.' : generalError(error)

  return (
    <div className="login-page">
      <div className="card login-card">
        <div className="brand">DentiCore</div>
        <p className="muted" style={{ margin: 0 }}>
          {slug ? (
            <>
              Clínica <strong>{slug}</strong>. Ingresa con tu cuenta para continuar.
            </>
          ) : (
            'Acceso de administración de la plataforma.'
          )}
        </p>

        <form onSubmit={handleSubmit} noValidate>
          {message && <div className="alert alert-error">{message}</div>}

          <Field
            label="Correo electrónico"
            name="email"
            type="email"
            value={form.email}
            onChange={handleChange}
            error={errors.email}
            autoComplete="username"
            required
          />
          <Field
            label="Contraseña"
            name="password"
            type="password"
            value={form.password}
            onChange={handleChange}
            error={errors.password}
            autoComplete="current-password"
            required
          />

          <button type="submit" className="btn" disabled={submitting}>
            {submitting ? 'Ingresando…' : 'Ingresar'}
          </button>
        </form>

        {slug ? (
          <p className="muted" style={{ margin: 0 }}>
            ¿No es tu clínica? <Link to={PLATFORM_LOGIN_PATH}>Cambiar de clínica</Link>
          </p>
        ) : (
          <ClinicCodeForm />
        )}
      </div>
    </div>
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
    <form onSubmit={handleSubmit} noValidate className="clinic-code-form">
      <Field
        label="¿Trabajas en una clínica? Escribe su código de acceso"
        name="clinicCode"
        value={code}
        onChange={(event) => setCode(event.target.value)}
        autoComplete="off"
      />
      <button type="submit" className="btn btn-secondary">
        Ir al acceso de la clínica
      </button>
    </form>
  )
}
