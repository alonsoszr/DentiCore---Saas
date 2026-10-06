import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'

const LISTS = [
  ['alergias', 'Alergias'],
  ['enfermedades', 'Enfermedades'],
  ['medicamentos', 'Medicamentos'],
]

/** Una línea por elemento (SDD §2.14.1). */
function toLines(items) {
  return (items ?? []).join('\n')
}

function toList(text) {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

/**
 * Antecedentes médicos (CUS-14, CUS-21; RF-064): solo con consentimiento vigente (RN-10). La API
 * responde 422 `RN-10` si falta; la pantalla lo indica antes y enlaza al consentimiento.
 */
export function MedicalHistoryPage({ patient }) {
  const { slug, appPath } = useClinic()
  const queryClient = useQueryClient()
  const history = patient.medical_history
  const [form, setForm] = useState(() => ({
    alergias: toLines(history?.alergias),
    enfermedades: toLines(history?.enfermedades),
    medicamentos: toLines(history?.medicamentos),
    observaciones: history?.observaciones ?? '',
  }))
  const [saved, setSaved] = useState(false)

  const save = useMutation({
    mutationFn: async (payload) => (await apiClient.put(`/patients/${patient.id}/medical-history`, payload)).data.data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['patients', slug, patient.id], updated)
      setSaved(true)
    },
  })

  const handleSubmit = (event) => {
    event.preventDefault()
    setSaved(false)
    save.mutate({
      alergias: toList(form.alergias),
      enfermedades: toList(form.enfermedades),
      medicamentos: toList(form.medicamentos),
      observaciones: form.observaciones.trim() || null,
    })
  }

  const errors = fieldErrors(save.error)
  const consentLink = <Link to={appPath(`/pacientes/${patient.id}/consentimiento`)}>Registrar consentimiento</Link>

  return (
    <div className="card">
      <h2>Antecedentes médicos</h2>
      {!patient.has_current_consent && (
        <div className="alert alert-error">
          El paciente no tiene un consentimiento vigente para la atención: no se pueden registrar antecedentes.{' '}
          {consentLink}
        </div>
      )}
      {saved && <div className="alert alert-success">Antecedentes guardados.</div>}
      {generalError(save.error) && <div className="alert alert-error">{generalError(save.error)}</div>}
      <form onSubmit={handleSubmit} noValidate>
        <div className="form-grid">
          {LISTS.map(([key, label]) => (
            <Field key={key} label={label} name={key} error={errors[key]} hint="Uno por línea, hasta 30.">
              <textarea
                id={key}
                name={key}
                rows={4}
                value={form[key]}
                onChange={(event) => setForm({ ...form, [key]: event.target.value })}
              />
            </Field>
          ))}
          <Field label="Observaciones" name="observaciones" error={errors.observaciones}>
            <textarea
              id="observaciones"
              name="observaciones"
              rows={4}
              maxLength={2000}
              value={form.observaciones}
              onChange={(event) => setForm({ ...form, observaciones: event.target.value })}
            />
          </Field>
        </div>
        <div className="form-actions">
          <button type="submit" className="btn" disabled={save.isPending || !patient.has_current_consent}>
            {save.isPending ? 'Guardando…' : 'Guardar antecedentes'}
          </button>
        </div>
      </form>
    </div>
  )
}
