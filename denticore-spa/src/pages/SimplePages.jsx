import { Link } from 'react-router-dom'
import { homePathFor } from '../auth/paths'
import { useAuth } from '../auth/useAuth'
import { PageHeader } from '../components/PageHeader'
import { PatientRecord } from './patients/PatientRecord'
import { usePatient } from './patients/usePatient'

/**
 * Portal del paciente: su propia ficha, localizada por el patient_uuid que devuelve
 * /auth/me. El backend (PatientPolicy) solo le permite ver esa ficha.
 */
export function PatientHomePage() {
  const { user } = useAuth()
  const patientQuery = usePatient(user.patient_uuid)

  return (
    <>
      <PageHeader title={`Hola, ${user.name}`} description="Esta es tu ficha en la clínica." />
      {user.patient_uuid ? (
        <PatientRecord query={patientQuery} />
      ) : (
        <div className="card">
          <p style={{ margin: 0 }}>
            Tu cuenta aún no está vinculada a una ficha de paciente. Consulta con la recepción de tu clínica.
          </p>
        </div>
      )}
    </>
  )
}

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
