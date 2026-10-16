import { Link } from 'react-router-dom'
import { homePathFor } from '../auth/paths'
import { useAuth } from '../auth/useAuth'

export function ForbiddenPage() {
  const { user } = useAuth()

  return (
    <div className="card">
      <h2>Acceso no permitido</h2>
      <p className="muted">Tu rol no tiene acceso a esta sección.</p>
      <Link to={homePathFor(user)}>Ir al inicio</Link>
    </div>
  )
}

export function NotFoundPage() {
  return (
    <div className="card">
      <h2>Página no encontrada</h2>
      <Link to="/">Ir al inicio</Link>
    </div>
  )
}
