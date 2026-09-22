import { Link } from 'react-router-dom'
import { homePathFor } from '../auth/roles'
import { useAuth } from '../auth/useAuth'
import { PageHeader } from '../components/PageHeader'

/** Inicio del rol patient: aún no hay endpoints para que consulte su propia ficha. */
export function PatientHomePage() {
  const { user } = useAuth()

  return (
    <>
      <PageHeader title={`Hola, ${user.name}`} />
      <div className="card">
        <p style={{ margin: 0 }}>
          Aquí podrás consultar tu historial y tus presupuestos. Estas secciones aún no están disponibles.
        </p>
      </div>
    </>
  )
}

export function ForbiddenPage() {
  const { user } = useAuth()

  return (
    <div className="card">
      <h2>Acceso no permitido</h2>
      <p className="muted">Tu rol no tiene acceso a esta sección.</p>
      <Link to={homePathFor(user?.role)}>Ir al inicio</Link>
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
