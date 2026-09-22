import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { fieldErrors, generalError } from '../api/errors'
import { Field } from '../components/Field'
import { homePathFor } from '../auth/roles'
import { useAuth } from '../auth/useAuth'

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ tenantSlug: '', email: '', password: '' })
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) {
    return <Navigate to={homePathFor(user.role)} replace />
  }

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })

  const handleSubmit = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      const { user: loggedUser } = await login(form)
      const from = location.state?.from?.pathname
      navigate(from ?? homePathFor(loggedUser.role), { replace: true })
    } catch (err) {
      setError(err)
    } finally {
      setSubmitting(false)
    }
  }

  const errors = fieldErrors(error)
  const message = generalError(error)

  return (
    <div className="login-page">
      <div className="card login-card">
        <div className="brand">DentiCore</div>
        <p className="muted" style={{ margin: 0 }}>
          Ingresa con tu cuenta para continuar.
        </p>

        <form onSubmit={handleSubmit} noValidate>
          {message && <div className="alert alert-error">{message}</div>}

          <Field
            label="Código de clínica"
            name="tenantSlug"
            value={form.tenantSlug}
            onChange={handleChange}
            error={errors.tenant_slug}
            hint="Déjalo vacío si eres administrador de la plataforma."
            autoComplete="organization"
          />
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
      </div>
    </div>
  )
}
