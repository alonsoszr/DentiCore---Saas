import { generalError } from '../../api/errors'
import { ageFrom, formatDate } from './format'

const HISTORY_LISTS = [
  ['alergias', 'Alergias'],
  ['enfermedades', 'Enfermedades'],
  ['medicamentos', 'Medicamentos'],
]

/**
 * Ficha del paciente (datos personales y antecedentes). La usan el personal
 * (/pacientes/:uuid) y el propio paciente desde su portal (/inicio).
 */
export function PatientRecord({ query }) {
  if (query.isLoading) return <div className="card empty">Cargando…</div>
  if (query.isError) return <div className="alert alert-error">{generalError(query.error)}</div>

  const patient = query.data
  if (!patient) return null
  const history = patient.medical_history

  return (
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

      <div className="card">
        <h2>Antecedentes médicos</h2>
        {!history && <p className="muted" style={{ margin: 0 }}>No se registraron antecedentes.</p>}
        {history && (
          <dl className="details">
            {HISTORY_LISTS.map(([key, label]) => (
              <div key={key}>
                <dt>{label}</dt>
                <dd>{history[key]?.length ? history[key].join(', ') : <span className="muted">Ninguna registrada</span>}</dd>
              </div>
            ))}
            <div style={{ gridColumn: '1 / -1' }}>
              <dt>Observaciones</dt>
              <dd style={{ whiteSpace: 'pre-wrap' }}>{history.observaciones ?? <span className="muted">—</span>}</dd>
            </div>
          </dl>
        )}
      </div>
    </>
  )
}
