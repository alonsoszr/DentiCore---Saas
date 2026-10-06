import { useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { useClinic } from '../../auth/useClinic'
import { formatCivilDate } from '../../ui/format'

export function PatientsPage() {
  const { slug, appPath } = useClinic()
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Number(searchParams.get('page') ?? 1)
  const term = searchParams.get('q') ?? ''
  const [search, setSearch] = useState(term)

  // CUS-13 (RF-054): nombre o apellido sin importar tildes ni mayúsculas.
  const patientsQuery = useQuery({
    queryKey: ['patients', slug, { page, term }],
    queryFn: async () => {
      // Un DNI de 8 dígitos se busca por el índice ciego (RF-056).
      if (/^\d{8}$/.test(term)) {
        try {
          const { data } = await apiClient.get('/patients/lookup', {
            params: { document_type: 'dni', document_number: term },
          })
          return { data: [data.data], meta: null }
        } catch (error) {
          if (error?.response?.status === 404) return { data: [], meta: null }
          throw error
        }
      }
      return (await apiClient.get('/patients', { params: { page, ...(term ? { q: term } : {}) } })).data
    },
    placeholderData: keepPreviousData,
  })

  const patients = patientsQuery.data?.data ?? []
  const meta = patientsQuery.data?.meta

  const goTo = (target) =>
    setSearchParams({ ...(term ? { q: term } : {}), ...(target > 1 ? { page: String(target) } : {}) })

  return (
    <>
      <PageHeader title="Pacientes" description="Fichas de los pacientes de la clínica.">
        <Link to={appPath('/pacientes/nuevo')} className="btn">
          Registrar paciente
        </Link>
      </PageHeader>

      <div className="card">
        <form
          className="form-actions"
          role="search"
          onSubmit={(event) => {
            event.preventDefault()
            setSearchParams(search.trim() ? { q: search.trim() } : {})
          }}
        >
          <Field
            label="Buscar paciente"
            name="q"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            hint="Nombre, apellido o DNI."
            maxLength={100}
          />
          <button type="submit" className="btn btn-secondary">
            Buscar
          </button>
        </form>
        {patientsQuery.isLoading && <div className="empty">Cargando…</div>}
        {patientsQuery.isError && <div className="alert alert-error">{generalError(patientsQuery.error)}</div>}
        {patientsQuery.isSuccess && patients.length === 0 && (
          <div className="empty">
            {term ? 'Ningún paciente coincide con la búsqueda.' : 'Aún no hay pacientes registrados.'}
          </div>
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
                    <tr key={patient.id}>
                      <td>
                        {patient.last_name}, {patient.first_name}
                      </td>
                      <td>{patient.document_number}</td>
                      <td className="muted">{formatCivilDate(patient.birth_date)}</td>
                      <td className="muted">{patient.phone ?? '—'}</td>
                      <td style={{ textAlign: 'right' }}>
                        <Link to={appPath(`/pacientes/${patient.id}`)}>Ver ficha</Link>
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
