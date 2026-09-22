import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { PageHeader } from '../../components/PageHeader'
import { formatDate } from './format'

export function PatientsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Number(searchParams.get('page') ?? 1)

  const patientsQuery = useQuery({
    queryKey: ['patients', page],
    queryFn: async () => (await apiClient.get('/patients', { params: { page } })).data,
    placeholderData: keepPreviousData,
  })

  const patients = patientsQuery.data?.data ?? []
  const meta = patientsQuery.data?.meta

  const goTo = (target) => setSearchParams(target > 1 ? { page: String(target) } : {})

  return (
    <>
      <PageHeader title="Pacientes" description="Fichas de los pacientes de la clínica.">
        <Link to="/pacientes/nuevo" className="btn">
          Registrar paciente
        </Link>
      </PageHeader>

      <div className="card">
        {patientsQuery.isLoading && <div className="empty">Cargando…</div>}
        {patientsQuery.isError && <div className="alert alert-error">{generalError(patientsQuery.error)}</div>}
        {patientsQuery.isSuccess && patients.length === 0 && (
          <div className="empty">Aún no hay pacientes registrados.</div>
        )}

        {patients.length > 0 && (
          <>
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Paciente</th>
                    <th>Documento</th>
                    <th>Fecha de nacimiento</th>
                    <th>Teléfono</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {patients.map((patient) => (
                    <tr key={patient.uuid}>
                      <td>
                        {patient.last_name}, {patient.first_name}
                      </td>
                      <td>{patient.document_id}</td>
                      <td className="muted">{formatDate(patient.birth_date)}</td>
                      <td className="muted">{patient.phone ?? '—'}</td>
                      <td style={{ textAlign: 'right' }}>
                        <Link to={`/pacientes/${patient.uuid}`}>Ver ficha</Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {meta && meta.last_page > 1 && (
              <div className="pagination">
                <span>
                  Página {meta.current_page} de {meta.last_page} · {meta.total} pacientes
                </span>
                <div>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={meta.current_page <= 1}
                    onClick={() => goTo(meta.current_page - 1)}
                  >
                    Anterior
                  </button>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={meta.current_page >= meta.last_page}
                    onClick={() => goTo(meta.current_page + 1)}
                  >
                    Siguiente
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </>
  )
}
