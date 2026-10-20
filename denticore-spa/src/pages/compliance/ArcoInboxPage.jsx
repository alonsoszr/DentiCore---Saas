import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { StatusBadge } from '../../components/StatusBadge'
import { TableWrap } from '../../components/TableWrap'
import { FileTextIcon } from '../../ui/icons'
import { formatCivilDate } from '../../ui/format'
import { ForbiddenPage } from '../SimplePages'
import { ArcoRequestDetail } from './ArcoRequestDetail'
import { ARCO_STATUSES, ARCO_TYPES, isDataOfficer } from './rights'

function Inbox() {
  const { slug } = useClinic()
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [selected, setSelected] = useState(null)
  const inbox = useQuery({
    queryKey: ['arco-requests', slug, status, type],
    queryFn: async () =>
      (await apiClient.get('/arco-requests', { params: { status: status || undefined, type: type || undefined } })).data
        .data,
  })
  return (
    <>
      <PageHeader title="Solicitudes ARCO" />
      <section className="card">
        <div className="form-grid">
          <Field label="Estado" name="filter-status">
            <select id="filter-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">Todos los estados</option>
              {Object.entries(ARCO_STATUSES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Tipo" name="filter-type">
            <select id="filter-type" value={type} onChange={(event) => setType(event.target.value)}>
              <option value="">Todos los tipos</option>
              {Object.entries(ARCO_TYPES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <button type="button" className="btn btn-secondary" onClick={() => inbox.refetch()} disabled={inbox.isFetching}>
          Actualizar bandeja
        </button>
        {inbox.isLoading && <p role="status">Cargando…</p>}
        {inbox.isError && <Alert tone="error">{generalError(inbox.error)}</Alert>}
        {inbox.isSuccess && inbox.data.length === 0 && <p>No hay solicitudes con estos filtros.</p>}
        {inbox.data?.length > 0 && (
          <TableWrap label="Solicitudes ARCO">
            <table>
              <thead>
                <tr>
                  <th scope="col">Titular</th>
                  <th scope="col">Tipo</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Fecha límite</th>
                  <th scope="col">Días hábiles restantes</th>
                  <th scope="col">Detalle</th>
                </tr>
              </thead>
              <tbody>
                {inbox.data.map((request) => (
                  <tr key={request.id}>
                    <td>{request.patient?.name}</td>
                    <td>{ARCO_TYPES[request.type]}</td>
                    <td>
                      <StatusBadge icon={FileTextIcon}>{ARCO_STATUSES[request.status]}</StatusBadge>
                    </td>
                    <td>{formatCivilDate(request.due_date)}</td>
                    <td>{request.remaining_business_days ?? 'Resuelta'}</td>
                    <td>
                      <button className="btn-link" onClick={() => setSelected(request.id)}>
                        Ver solicitud
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableWrap>
        )}
      </section>
      {selected && <ArcoRequestDetail key={selected} id={selected} />}
    </>
  )
}

/** La guardia evita hasta la consulta si el administrador no es Oficial de Datos Personales. */
export function ArcoInboxPage() {
  const { user } = useAuth()
  return isDataOfficer(user) ? <Inbox /> : <ForbiddenPage />
}
