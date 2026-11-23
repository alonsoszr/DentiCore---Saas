import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { ROLE_LABELS } from '../../auth/roles'
import { PageHeader } from '../../components/PageHeader'
import { Field } from '../../components/Field'
import { TableWrap } from '../../components/TableWrap'
import { formatDateTime } from '../../ui/format'
import { useTimeZone } from '../../ui/useTimeZone'

function downloadCsv(blob) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'denticore-bitacora.csv'
  link.click()
  URL.revokeObjectURL(url)
}

const fields = [
  { key: 'from', label: 'Desde', type: 'date' },
  { key: 'to', label: 'Hasta', type: 'date' },
  { key: 'user_id', label: 'ID de usuario', type: 'text' },
  { key: 'action', label: 'Acción', type: 'text' },
  { key: 'resource_type', label: 'Tipo de recurso', type: 'text' },
  { key: 'resource_id', label: 'ID de recurso', type: 'text' },
]

export function AuditLogPage({ platform = false, download = downloadCsv }) {
  const { user } = useAuth()
  const timeZone = useTimeZone()
  const endpoint = platform ? '/platform/audit-logs' : '/audit-logs'
  const [draft, setDraft] = useState({})
  const [filters, setFilters] = useState({})
  const [page, setPage] = useState(1)
  const cleanDraft = Object.fromEntries(
    Object.entries(draft)
      .map(([key, value]) => [key, value.trim()])
      .filter(([, value]) => value !== ''),
  )
  const query = useQuery({
    queryKey: ['audit-log', user?.id, user?.tenant?.id, endpoint, filters, page],
    queryFn: async () => (await apiClient.get(endpoint, { params: { ...filters, page } })).data,
  })
  const csv = useMutation({
    mutationFn: async (params) => {
      try {
        return (await apiClient.post(`${endpoint}/exports`, params, { responseType: 'blob' })).data
      } catch (error) {
        const body = error.response?.data
        if (body instanceof Blob && body.type.includes('json')) {
          try {
            error.response.data = JSON.parse(await body.text())
          } catch {
            // El error HTTP sigue disponible aunque la respuesta no contenga un JSON válido.
          }
        }
        throw error
      }
    },
    onSuccess: download,
  })
  const errors = fieldErrors(csv.error ?? query.error)
  const message = generalError(csv.error ?? query.error)
  const rows = query.data?.data ?? []
  const lastPage = query.data?.meta?.last_page ?? 1

  return (
    <>
      <PageHeader title="Bitácora de auditoría" />
      <section className="card mb-4" aria-label="Filtros de bitácora">
        <form
          onSubmit={(event) => {
            event.preventDefault()
            csv.reset()
            setFilters(cleanDraft)
            setPage(1)
          }}
        >
          <div className="form-grid">
            {fields.map(({ key, label, type }) => (
              <Field
                key={key}
                label={label}
                name={`audit-${key}`}
                type={type}
                error={errors[key]}
                value={draft[key] ?? ''}
                onChange={(event) => setDraft({ ...draft, [key]: event.target.value })}
              />
            ))}
          </div>
          <div className="form-actions">
            <button type="submit" className="btn">
              Filtrar
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={!filters.from || !filters.to || !query.isSuccess || csv.isPending}
              onClick={() => csv.mutate(filters)}
            >
              Exportar CSV
            </button>
          </div>
        </form>
      </section>
      {message && (
        <p role="alert" className="alert alert-error">
          {message}
        </p>
      )}
      {query.isPending && <p role="status">Cargando bitácora…</p>}
      {query.isError && (
        <button type="button" className="btn btn-secondary" onClick={() => query.refetch()}>
          Reintentar
        </button>
      )}
      {query.isSuccess && rows.length === 0 && <p role="status">No hay eventos para estos filtros.</p>}
      {rows.length > 0 && (
        <section className="card" aria-label="Eventos de auditoría">
          <TableWrap label="Eventos de auditoría">
            <table>
              <thead>
                <tr>
                  <th scope="col">Fecha</th>
                  {platform && <th scope="col">Clínica</th>}
                  <th scope="col">Acción</th>
                  <th scope="col">Usuario</th>
                  <th scope="col">Recurso</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td>{row.created_at ? formatDateTime(row.created_at, timeZone) : '—'}</td>
                    {platform && <td>{row.clinic?.name ?? 'Plataforma'}</td>}
                    <td>{row.action}</td>
                    <td>{row.user?.name ?? ROLE_LABELS[row.actor_role] ?? 'Sistema'}</td>
                    <td>{row.resource_type ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
          <div className="form-actions">
            <button type="button" className="btn btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Anterior
            </button>
            <span>
              Página {page} de {lastPage}
            </span>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={page >= lastPage}
              onClick={() => setPage(page + 1)}
            >
              Siguiente
            </button>
          </div>
        </section>
      )}
    </>
  )
}
