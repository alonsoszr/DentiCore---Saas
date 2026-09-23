import { Link, useLocation, useParams } from 'react-router-dom'
import { PageHeader } from '../../components/PageHeader'
import { PatientRecord } from './PatientRecord'
import { usePatient } from './usePatient'

export function PatientDetailPage() {
  const { uuid } = useParams()
  const location = useLocation()
  const patientQuery = usePatient(uuid)
  const patient = patientQuery.data

  return (
    <>
      <PageHeader title={patient ? `${patient.first_name} ${patient.last_name}` : 'Ficha del paciente'}>
        <Link to="/pacientes" className="btn btn-secondary">
          Volver
        </Link>
      </PageHeader>

      {location.state?.created && <div className="alert alert-success">Paciente registrado correctamente.</div>}

      <PatientRecord query={patientQuery} />
    </>
  )
}
