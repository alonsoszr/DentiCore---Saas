import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Field } from '../../components/Field'
import { formatCivilDate } from '../../ui/format'
import { DOCUMENT_TYPES, today } from './patientIdentity'

const RELATIONSHIPS = { madre: 'Madre', padre: 'Padre', tutor: 'Tutor', curador: 'Curador', otro: 'Otro' }
const END_REASONS = { mayoria_de_edad: 'Mayoría de edad', revocada: 'Revocada', otro: 'Otro' }
const EDIT_ROLES = ['clinic_admin', 'receptionist']

const EMPTY = {
  document_type: 'dni',
  document_number: '',
  first_name: '',
  last_name: '',
  relationship: 'madre',
  phone: '',
  email: '',
}

/** Representantes legales del paciente (CUS-16; RF-059 a RF-061, RN-12). */
export function RepresentativesPage({ patient }) {
  const { slug } = useClinic()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(EMPTY)
  const [showForm, setShowForm] = useState(false)
  const canEdit = EDIT_ROLES.includes(user?.role)
  const queryKey = ['representatives', slug, patient.id]

  const listQuery = useQuery({
    queryKey,
    queryFn: async () => (await apiClient.get(`/patients/${patient.id}/representatives`)).data.data,
  })

  const add = useMutation({
    mutationFn: async (payload) => (await apiClient.post(`/patients/${patient.id}/representatives`, payload)).data.data,
    onSuccess: () => {
      setForm(EMPTY)
      setShowForm(false)
      queryClient.invalidateQueries({ queryKey })
    },
  })
  const end = useMutation({
    mutationFn: async ({ id, reason }) =>
      (await apiClient.post(`/patients/${patient.id}/representatives/${id}/end`, { reason })).data.data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })
  const handleSubmit = (event) => {
    event.preventDefault()
    add.mutate({ ...form, email: form.email || null, valid_from: today() })
  }

  const errors = fieldErrors(add.error)
  const representatives = listQuery.data ?? []

  return (
    <div className="card">
      <div className="page-header">
        <h2 className="m-0">Representantes legales</h2>
        {canEdit && !showForm && (
          <button type="button" className="btn" onClick={() => setShowForm(true)}>
            Agregar representante
          </button>
        )}
      </div>
      {patient.is_minor && representatives.every((item) => !item.is_current) && listQuery.isSuccess && (
        <div className="alert alert-error">El paciente es menor de edad y no tiene un representante vigente.</div>
      )}
      {generalError(end.error) && <div className="alert alert-error">{generalError(end.error)}</div>}

      {showForm && (
        <form onSubmit={handleSubmit} noValidate>
          {generalError(add.error) && <div className="alert alert-error">{generalError(add.error)}</div>}
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
            <Field label="Parentesco" name="relationship" error={errors.relationship}>
              <select id="relationship" name="relationship" value={form.relationship} onChange={handleChange}>
                {Object.entries(RELATIONSHIPS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
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
              label="Correo (opcional)"
              name="email"
              type="email"
              value={form.email}
              onChange={handleChange}
              error={errors.email}
            />
          </div>
          <div className="form-actions">
            <button type="submit" className="btn" disabled={add.isPending}>
              {add.isPending ? 'Guardando…' : 'Guardar representante'}
            </button>
            <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>
              Cancelar
            </button>
          </div>
        </form>
      )}

      {listQuery.isSuccess && representatives.length === 0 && (
        <div className="empty">Sin representantes registrados.</div>
      )}
      {representatives.length > 0 && (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Representante</th>
                <th>Parentesco</th>
                <th>Documento</th>
                <th>Vigencia</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {representatives.map((item) => (
                <tr key={item.id}>
                  <td>
                    {item.first_name} {item.last_name}
                  </td>
                  <td>{RELATIONSHIPS[item.relationship]}</td>
                  <td>
                    {DOCUMENT_TYPES[item.document_type]} {item.document_number}
                  </td>
                  <td>
                    {item.is_current
                      ? `Desde ${formatCivilDate(item.valid_from)}`
                      : `${formatCivilDate(item.valid_from)} – ${formatCivilDate(item.valid_until)} (${END_REASONS[item.ended_reason] ?? item.ended_reason})`}
                  </td>
                  <td>
                    {canEdit && item.is_current && (
                      <button
                        type="button"
                        className="btn-link"
                        onClick={() => {
                          // RNF-063: la acción pide confirmación con sus efectos.
                          if (window.confirm(`¿Terminar la representación de ${item.first_name} ${item.last_name}?`)) {
                            end.mutate({ id: item.id, reason: 'revocada' })
                          }
                        }}
                      >
                        Terminar
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
