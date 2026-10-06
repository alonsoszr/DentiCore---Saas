import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { DOCUMENT_TYPES } from './patientIdentity'

const FIELDS = [
  'document_type',
  'document_number',
  'first_name',
  'last_name',
  'birth_date',
  'sex',
  'phone',
  'email',
  'address',
]

/**
 * Edición de la identificación y el contacto (CUS-15; RF-062). Solo se envían los campos que
 * cambiaron; la API guarda los valores anteriores cifrados. El número de HC no cambia (RN-79).
 */
export function PatientEditPage({ patient }) {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const initial = Object.fromEntries(FIELDS.map((field) => [field, patient[field] ?? '']))
  const [form, setForm] = useState(initial)
  const [saved, setSaved] = useState(false)

  const update = useMutation({
    mutationFn: async (payload) => (await apiClient.patch(`/patients/${patient.id}`, payload)).data.data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['patients', slug, patient.id], updated)
      queryClient.invalidateQueries({ queryKey: ['patients', slug], exact: false })
      setSaved(true)
    },
  })

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })

  const handleSubmit = (event) => {
    event.preventDefault()
    setSaved(false)
    const changed = {}
    for (const field of FIELDS) {
      if (form[field] !== initial[field]) changed[field] = form[field] === '' ? null : form[field]
    }
    // El documento se valida como par: si cambia uno, se envían ambos.
    if ('document_type' in changed || 'document_number' in changed) {
      changed.document_type = form.document_type
      changed.document_number = form.document_number
    }
    update.mutate(changed)
  }

  const errors = fieldErrors(update.error)

  return (
    <div className="card">
      <h2>Editar identificación</h2>
      <p className="muted">El número de historia clínica no cambia: {patient.clinical_record_number}.</p>
      {saved && <div className="alert alert-success">Datos actualizados.</div>}
      {generalError(update.error) && <div className="alert alert-error">{generalError(update.error)}</div>}
      <form onSubmit={handleSubmit} noValidate>
        <div className="form-grid">
          <Field label="Tipo de documento" name="document_type" error={errors.document_type}>
            <select id="document_type" name="document_type" value={form.document_type} onChange={handleChange}>
              {Object.entries(DOCUMENT_TYPES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label="Número de documento"
            name="document_number"
            value={form.document_number}
            onChange={handleChange}
            error={errors.document_number}
            autoComplete="off"
          />
          <Field
            label="Nombres"
            name="first_name"
            value={form.first_name}
            onChange={handleChange}
            error={errors.first_name}
          />
          <Field
            label="Apellidos"
            name="last_name"
            value={form.last_name}
            onChange={handleChange}
            error={errors.last_name}
          />
          <Field
            label="Fecha de nacimiento"
            name="birth_date"
            type="date"
            value={form.birth_date}
            onChange={handleChange}
            error={errors.birth_date}
          />
          <Field label="Sexo" name="sex" error={errors.sex}>
            <select id="sex" name="sex" value={form.sex} onChange={handleChange}>
              <option value="">Selecciona…</option>
              <option value="femenino">Femenino</option>
              <option value="masculino">Masculino</option>
            </select>
          </Field>
          <Field
            label="Teléfono"
            name="phone"
            type="tel"
            value={form.phone}
            onChange={handleChange}
            error={errors.phone}
          />
          <Field
            label="Correo electrónico (opcional)"
            name="email"
            type="email"
            value={form.email}
            onChange={handleChange}
            error={errors.email}
          />
          <Field
            label="Dirección (opcional)"
            name="address"
            value={form.address}
            onChange={handleChange}
            error={errors.address}
          />
        </div>
        <div className="form-actions">
          <button type="submit" className="btn" disabled={update.isPending}>
            {update.isPending ? 'Guardando…' : 'Guardar cambios'}
          </button>
        </div>
      </form>
    </div>
  )
}
