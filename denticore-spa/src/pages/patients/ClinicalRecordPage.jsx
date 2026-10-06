import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Odontogram } from '../../components/odontogram/Odontogram'
import { ToothHistory } from '../../components/odontogram/ToothHistory'
import { formatDateTime } from '../../ui/format'
import { ATTENTION_STATUS, DENTITIONS } from '../attentions/labels'

/**
 * Historia clínica del paciente (CUS-21, CUS-24, CUS-25; RF-076, RF-079, RF-081): atenciones,
 * odontograma vigente e inicial por separado e historial por pieza. El odontólogo abre desde
 * aquí una atención («Atender»); recepción consulta en solo lectura.
 */
export function ClinicalRecordPage({ patient }) {
  const { user } = useAuth()
  const { slug, appPath } = useClinic()
  const navigate = useNavigate()
  const [view, setView] = useState('vigente')
  const [dentition, setDentition] = useState(null)
  const [tooth, setTooth] = useState(null)
  const canOpenAttentions = ['clinic_admin', 'dentist'].includes(user?.role)

  const record = useQuery({
    queryKey: ['clinical-record', slug, patient.id],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/clinical-record`)).data.data,
  })
  const odontogram = useQuery({
    queryKey: ['odontogram', slug, patient.id, view],
    queryFn: async () =>
      (await apiClient.get(`/patients/${patient.id}/odontogram${view === 'inicial' ? '/initial' : ''}`)).data.data,
  })
  const history = useQuery({
    queryKey: ['tooth-history', slug, patient.id, tooth],
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/teeth/${tooth}/history`)).data.data,
    enabled: tooth !== null,
  })

  const open = useMutation({
    mutationFn: async () => (await apiClient.post(`/patients/${patient.id}/attentions`)).data,
    onSuccess: (data) =>
      navigate(appPath(`/atenciones/${data.data.id}`), { state: { consentWarning: data.consent_warning } }),
    onError: (error) => {
      // RF-082: el odontólogo ya tiene una atención abierta con el paciente; se retoma.
      const current = (record.data?.attentions ?? []).find(
        (attention) => attention.status === 'abierta' && attention.dentist.id === user?.id,
      )
      if (error.response?.status === 409 && current) navigate(appPath(`/atenciones/${current.id}`))
    },
  })

  const attentions = record.data?.attentions ?? []
  const shownDentition = dentition ?? odontogram.data?.default_dentition ?? 'permanente'

  return (
    <>
      <section className="card" aria-labelledby="attentions-title">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="attentions-title">Atenciones</h2>
          {user?.role === 'dentist' && (
            <button type="button" className="btn" onClick={() => open.mutate()} disabled={open.isPending}>
              Atender
            </button>
          )}
        </div>
        {open.isError && <div className="alert alert-error">{generalError(open.error)}</div>}
        {record.isLoading && <div className="empty">Cargando…</div>}
        {record.isError && <div className="alert alert-error">{generalError(record.error)}</div>}
        {record.isSuccess && attentions.length === 0 && (
          <div className="empty">El paciente aún no tiene atenciones.</div>
        )}
        {attentions.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th scope="col">Apertura</th>
                  <th scope="col">Odontólogo</th>
                  <th scope="col">Estado</th>
                  {canOpenAttentions && <th scope="col">Detalle</th>}
                </tr>
              </thead>
              <tbody>
                {attentions.map((attention) => (
                  <tr key={attention.id}>
                    <td className="tabular-nums">{formatDateTime(attention.opened_at)}</td>
                    <td>
                      {attention.dentist.name} · COP {attention.dentist.cop}
                    </td>
                    <td>
                      <span className={attention.status === 'abierta' ? 'badge' : 'badge badge-muted'}>
                        {ATTENTION_STATUS[attention.status]}
                      </span>
                    </td>
                    {canOpenAttentions && (
                      <td>
                        <Link
                          to={appPath(`/atenciones/${attention.id}`)}
                          aria-label={`Ver la atención del ${formatDateTime(attention.opened_at)}`}
                        >
                          Ver
                        </Link>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="card" aria-labelledby="odontogram-title">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 id="odontogram-title">Odontograma</h2>
          <div className="flex flex-wrap items-end gap-3">
            <div role="group" aria-label="Odontograma a mostrar" className="flex gap-2">
              {[
                ['vigente', 'Vigente'],
                ['inicial', 'Inicial'],
              ].map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  className={view === value ? 'btn' : 'btn btn-secondary'}
                  aria-pressed={view === value}
                  onClick={() => setView(value)}
                >
                  {label}
                </button>
              ))}
            </div>
            <div className="field">
              <label htmlFor="dentition">Dentición</label>
              <div className="field-control">
                <select id="dentition" value={shownDentition} onChange={(event) => setDentition(event.target.value)}>
                  {Object.entries(DENTITIONS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        </div>

        {odontogram.isLoading && <div className="empty">Cargando…</div>}
        {odontogram.isError && <div className="alert alert-error">{generalError(odontogram.error)}</div>}
        {odontogram.isSuccess && view === 'inicial' && odontogram.data === null && (
          <div className="empty">El paciente aún no tiene odontograma inicial.</div>
        )}
        {odontogram.isSuccess && odontogram.data && (
          <>
            {view === 'inicial' && (
              <p className="muted">
                {odontogram.data.status === 'cerrado'
                  ? `Odontograma inicial cerrado el ${formatDateTime(odontogram.data.closed_at)}.`
                  : 'Odontograma inicial abierto: se completa durante la primera atención.'}
              </p>
            )}
            <Odontogram
              label={view === 'inicial' ? 'Odontograma inicial' : 'Odontograma vigente'}
              entries={odontogram.data.entries}
              dentition={shownDentition}
              selectedTooth={tooth}
              onSelectTooth={setTooth}
            />
          </>
        )}
      </section>

      {tooth !== null && (
        <section className="card">
          {history.isLoading && <div className="empty">Cargando…</div>}
          {history.isError && <div className="alert alert-error">{generalError(history.error)}</div>}
          {history.isSuccess && <ToothHistory tooth={tooth} entries={history.data} />}
        </section>
      )}
    </>
  )
}
