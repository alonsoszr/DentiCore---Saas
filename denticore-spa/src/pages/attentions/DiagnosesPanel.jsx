import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { Cie10Picker } from './Cie10Picker'

const TYPES = { presuntivo: 'Presuntivo', definitivo: 'Definitivo' }
const ORIGINS = { nota: 'Nota', adenda: 'Adenda' }

/** Lista de diagnósticos CIE-10 de la atención; con `onRemove`, cada uno se puede quitar. */
export function DiagnosesList({ diagnoses, onRemove }) {
  if (diagnoses.length === 0) return <p className="muted m-0">Sin diagnósticos registrados.</p>

  return (
    <ul className="m-0 flex list-none flex-col gap-2 p-0">
      {diagnoses.map((diagnosis) => (
        <li key={diagnosis.id ?? diagnosis.cie10_code} className="flex flex-wrap items-center gap-2">
          <span className="font-semibold tabular-nums">{diagnosis.code ?? diagnosis.cie10_code}</span>
          <span>{diagnosis.description}</span>
          <span className="badge badge-muted">{TYPES[diagnosis.type]}</span>
          {diagnosis.origin && <span className="muted">{ORIGINS[diagnosis.origin]}</span>}
          {onRemove && (
            <button type="button" className="btn btn-link" onClick={() => onRemove(diagnosis)}>
              Quitar {diagnosis.code ?? diagnosis.cie10_code}
            </button>
          )}
        </li>
      ))}
    </ul>
  )
}

/** Diagnósticos de la nota mientras la atención está abierta (CUS-80; RF-084, RF-085). */
export function DiagnosesPanel({ attention, queryKey }) {
  const queryClient = useQueryClient()
  const refresh = () => queryClient.invalidateQueries({ queryKey })

  const add = useMutation({
    mutationFn: async ({ cie10_code, type }) =>
      (await apiClient.post(`/attentions/${attention.id}/diagnoses`, { cie10_code, type })).data.data,
    onSuccess: refresh,
  })
  const remove = useMutation({
    mutationFn: async (diagnosis) => apiClient.delete(`/attentions/${attention.id}/diagnoses/${diagnosis.id}`),
    onSuccess: refresh,
  })
  const error = add.error ?? remove.error

  return (
    <section className="card flex flex-col gap-3" aria-labelledby="diagnoses-title">
      <h2 id="diagnoses-title">Diagnósticos CIE-10</h2>
      <DiagnosesList diagnoses={attention.diagnoses ?? []} onRemove={(diagnosis) => remove.mutate(diagnosis)} />
      <Cie10Picker
        onAdd={(picked) => add.mutate(picked)}
        busy={add.isPending}
        error={fieldErrors(add.error).cie10_code}
      />
      {error && generalError(error) && <div className="alert alert-error">{generalError(error)}</div>}
    </section>
  )
}
