import { useQuery } from '@tanstack/react-query'
import { Link, useLocation, useParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { PageHeader } from '../../components/PageHeader'
import { ageFrom, formatDate } from './format'

export function PatientDetailPage() {
  const { uuid } = useParams()
  const location = useLocation()

  const patientQuery = useQuery({
    queryKey: ['patients', 'detail', uuid],
    queryFn: async () => (await apiClient.get(`/patients/${uuid}`)).data.data,
    retry: false,
  })

  const patient = patientQuery.data

  return (
    <>
      <PageHeader title={patient ? `${patient.first_name} ${patient.last_name}` : 'Ficha del paciente'}>
        <Link to="/pacientes" className="btn btn-secondary">
          Volver
        </Link>
      </PageHeader>

      {location.state?.created && <div className="alert alert-success">Paciente registrado correctamente.</div>}

      {patientQuery.isLoading && <div className="card empty">Cargando…</div>}
      {patientQuery.isError && <div className="alert alert-error">{generalError(patientQuery.error)}</div>}

      {patient && (
        <>
          <div className="card">
            <h2>Datos personales</h2>
            <dl className="details">
              <div>
                <dt>Documento de identidad</dt>
                <dd>{patient.document_id}</dd>
              </div>
              <div>
                <dt>Fecha de nacimiento</dt>
                <dd>
                  {formatDate(patient.birth_date)} <span className="muted">({ageFrom(patient.birth_date)} años)</span>
                </dd>
              </div>
              <div>
                <dt>Teléfono</dt>
                <dd>{patient.phone ?? '—'}</dd>
              </div>
              <div>
                <dt>Correo electrónico</dt>
                <dd>{patient.email ?? '—'}</dd>
              </div>
              <div>
                <dt>Cuenta de portal</dt>
                <dd>{patient.user_uuid ? 'Vinculada' : 'Sin cuenta vinculada'}</dd>
              </div>
            </dl>
          </div>

          {patient.medical_history && (
            <div className="card">
              <h2>Antecedentes médicos</h2>
              <pre style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
                {JSON.stringify(patient.medical_history, null, 2)}
              </pre>
            </div>
          )}
        </>
      )}
    </>
  )
}
