import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field } from '../../components/Field'
import { FINDING_COLORS } from '../../components/odontogram/teeth'
import { formatDate } from '../../ui/format'
import { findingText, siteText } from './labels'

/**
 * Hallazgos pendientes de decisión (/c/:slug/app/pacientes/:uuid/pendientes; CUS-34; RF-111 a
 * RF-113, RN-27): hallazgos rojos vigentes sin ítem de plan ni decisión de no tratar. Desde aquí se
 * convierten en ítems de un plan o se marcan «no tratar» con motivo.
 */
export function PendingFindingsPage({ patient }) {
  const { slug, appPath } = useClinic()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState([])
  const [declining, setDeclining] = useState(null)
  const [reason, setReason] = useState('')
  const queryKey = ['pending-findings', slug, patient.id]

  const pending = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/pending-findings`)).data.data,
  })
  const decline = useMutation({
    mutationFn: async ({ entry, text }) => apiClient.post(`/odontogram-entries/${entry.id}/no-treat`, { reason: text }),
    onSuccess: () => {
      setDeclining(null)
      setSelected((ids) => ids.filter((id) => id !== declining?.id))
      queryClient.invalidateQueries({ queryKey })
    },
  })

  const entries = pending.data ?? []
  const toggle = (id) => setSelected((ids) => (ids.includes(id) ? ids.filter((item) => item !== id) : [...ids, id]))
  const createItems = () =>
    navigate(appPath(`/pacientes/${patient.id}/planes`), {
      state: {
        fromFindings: entries
          .filter((entry) => selected.includes(entry.id))
          .map((entry) => ({ id: entry.id, tooth: entry.tooth, surfaces: entry.surfaces, label: findingText(entry) })),
      },
    })
  const reasonError = fieldErrors(decline.error).reason

  return (
    <div className="card">
      <div className="page-header">
        <h2 className="m-0">Hallazgos pendientes de decisión</h2>
        <button type="button" className="btn" onClick={createItems} disabled={selected.length === 0}>
          Crear ítems del plan ({selected.length})
        </button>
      </div>
      <p className="muted">
        Todo hallazgo rojo vigente debe quedar en un ítem del plan o marcado «no tratar» con su motivo (RN-27).
      </p>

      {pending.isLoading && <div className="empty">Cargando…</div>}
      {pending.isError && <div className="alert alert-error">{generalError(pending.error)}</div>}
      {pending.isSuccess && entries.length === 0 && (
        <div className="alert alert-success">No hay hallazgos pendientes de decisión.</div>
      )}
      {entries.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>
                  <span className="sr-only">Seleccionar</span>
                </th>
                <th>Pieza</th>
                <th>Hallazgo</th>
                <th>Registrado</th>
                <th>
                  <span className="sr-only">Acciones</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.id}>
                  <td>
                    <input
                      type="checkbox"
                      aria-label={`Seleccionar ${findingText(entry)} en la pieza ${siteText(entry.tooth, [], entry.tooth_end)}`}
                      checked={selected.includes(entry.id)}
                      onChange={() => toggle(entry.id)}
                    />
                  </td>
                  <td>{siteText(entry.tooth, entry.surfaces, entry.tooth_end)}</td>
                  <td>
                    {/* Color oficial NTS 188 siempre con su sigla (DESIGN.md › odontograma). */}
                    <span className={`font-semibold ${FINDING_COLORS.rojo.text}`}>{findingText(entry)}</span>
                  </td>
                  <td>{formatDate(entry.recorded_at)}</td>
                  <td>
                    <button
                      type="button"
                      className="btn-link"
                      onClick={() => {
                        decline.reset()
                        setReason('')
                        setDeclining(entry)
                      }}
                    >
                      No tratar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {declining && (
        <ConfirmDialog
          title={`No tratar ${findingText(declining)} en la pieza ${declining.tooth}`}
          confirmLabel="Registrar decisión"
          onConfirm={() => decline.mutate({ entry: declining, text: reason })}
          onCancel={() => setDeclining(null)}
          busy={decline.isPending}
          confirmDisabled={reason.trim().length < 10}
          error={reasonError ? null : generalError(decline.error)}
        >
          <p>La decisión queda registrada y el hallazgo sale de los pendientes; no se puede deshacer.</p>
          <Field label="Motivo" name="no-treat-reason" error={reasonError} hint="Al menos 10 caracteres.">
            <textarea
              id="no-treat-reason"
              rows={3}
              maxLength={500}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              aria-invalid={reasonError ? true : undefined}
              aria-describedby={reasonError ? 'no-treat-reason-error' : 'no-treat-reason-hint'}
            />
          </Field>
        </ConfirmDialog>
      )}
    </div>
  )
}
