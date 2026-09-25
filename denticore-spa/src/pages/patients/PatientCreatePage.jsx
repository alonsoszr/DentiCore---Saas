import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'

const EMPTY_FORM = {
  document_id: '',
  first_name: '',
  last_name: '',
  birth_date: '',
  phone: '',
  email: '',
  user_uuid: '',
  alergias: '',
  enfermedades: '',
  medicamentos: '',
  observaciones: '',
}

const HISTORY_FIELDS = ['alergias', 'enfermedades', 'medicamentos', 'observaciones']

/** "Penicilina, Látex" → ['Penicilina', 'Látex'] */
function toList(text) {
  return text
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

/**
 * Payload de la API: opcionales vacíos como null y antecedentes con la estructura fija
 * de medical_history (el backend normaliza y guarda null si no hay ninguno).
 */
function toPayload(form) {
  const payload = {}
  for (const [key, value] of Object.entries(form)) {
    if (!HISTORY_FIELDS.includes(key)) payload[key] = value === '' ? null : value
  }
  payload.medical_history = {
    alergias: toList(form.alergias),
    enfermedades: toList(form.enfermedades),
    medicamentos: toList(form.medicamentos),
    observaciones: form.observaciones.trim() || null,
  }
  return payload
}

/** Error del primer elemento inválido de una lista (p. ej. medical_history.alergias.0). */
function historyError(errors, key) {
  const entry = Object.entries(errors).find(
    ([field]) => field === `medical_history.${key}` || field.startsWith(`medical_history.${key}.`),
  )
  return entry?.[1]
}

export function PatientCreatePage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(EMPTY_FORM)
  // Solo clinic_admin puede listar usuarios (GET /users), así que solo él puede elegir
  // la cuenta de portal a vincular.
  const canLinkPortalAccount = user.role === 'clinic_admin'

  const portalUsersQuery = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await apiClient.get('/users')).data.data,
    enabled: canLinkPortalAccount,
    // Solo cuentas de paciente activas que aún no tienen ficha vinculada.
    select: (users) => users.filter((u) => u.role === 'patient' && u.is_active && !u.patient_uuid),
  })

  const createPatient = useMutation({
    mutationFn: async (payload) => (await apiClient.post('/patients', payload)).data.data,
    onSuccess: (patient) => {
      queryClient.invalidateQueries({ queryKey: ['patients'] })
      queryClient.invalidateQueries({ queryKey: ['users'] })
      navigate(`/pacientes/${patient.uuid}`, { state: { created: true } })
    },
  })

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })

  const handleSubmit = (event) => {
    event.preventDefault()
    createPatient.mutate(toPayload(form))
  }

  const errors = fieldErrors(createPatient.error)

  return (
    <>
      <PageHeader title="Registrar paciente" description="El documento y el teléfono se guardan cifrados.">
        <Link to="/pacientes" className="btn btn-secondary">
          Volver
        </Link>
      </PageHeader>

      <div className="card">
        {generalError(createPatient.error) && (
          <div className="alert alert-error">{generalError(createPatient.error)}</div>
        )}
        <form onSubmit={handleSubmit} noValidate autoComplete="off">
          <div className="form-grid">
            <Field label="Nombres" name="first_name" autoComplete="off" value={form.first_name} onChange={handleChange} error={errors.first_name} />
            <Field label="Apellidos" name="last_name" autoComplete="off" value={form.last_name} onChange={handleChange} error={errors.last_name} />
            <Field
              label="Documento de identidad (DNI)"
              name="document_id"
              autoComplete="off"
              value={form.document_id}
              onChange={handleChange}
              error={errors.document_id}
              maxLength={20}
            />
            <Field
              label="Fecha de nacimiento"
              name="birth_date"
              type="date"
              value={form.birth_date}
              onChange={handleChange}
              error={errors.birth_date}
            />
            <Field
              label="Teléfono (opcional)"
              name="phone"
              autoComplete="off"
              type="tel"
              value={form.phone}
              onChange={handleChange}
              error={errors.phone}
              maxLength={20}
            />
            <Field
              label="Correo electrónico (opcional)"
              name="email"
              autoComplete="off"
              type="email"
              value={form.email}
              onChange={handleChange}
              error={errors.email}
            />
            {canLinkPortalAccount && (
              <Field
                label="Cuenta de portal (opcional)"
                name="user_uuid"
                error={errors.user_uuid}
                hint="Usuario con rol Paciente que podrá consultar esta ficha."
              >
                <select id="user_uuid" name="user_uuid" value={form.user_uuid} onChange={handleChange}>
                  <option value="">Sin cuenta vinculada</option>
                  {(portalUsersQuery.data ?? []).map((portalUser) => (
                    <option key={portalUser.uuid} value={portalUser.uuid}>
                      {portalUser.name} — {portalUser.email}
                    </option>
                  ))}
                </select>
              </Field>
            )}
          </div>

          <h3 className="form-section">Antecedentes médicos (opcional)</h3>
          {errors.medical_history && <div className="alert alert-error">{errors.medical_history}</div>}
          <div className="form-grid">
            <Field
              label="Alergias"
              name="alergias"
              autoComplete="off"
              value={form.alergias}
              onChange={handleChange}
              error={historyError(errors, 'alergias')}
              hint="Separa varias con comas. Ej.: Penicilina, Látex"
            />
            <Field
              label="Enfermedades"
              name="enfermedades"
              autoComplete="off"
              value={form.enfermedades}
              onChange={handleChange}
              error={historyError(errors, 'enfermedades')}
              hint="Separa varias con comas. Ej.: Diabetes, Hipertensión"
            />
            <Field
              label="Medicamentos"
              name="medicamentos"
              autoComplete="off"
              value={form.medicamentos}
              onChange={handleChange}
              error={historyError(errors, 'medicamentos')}
              hint="Separa varios con comas."
            />
          </div>
          <div className="form-grid" style={{ marginTop: 14 }}>
            <Field label="Observaciones" name="observaciones" error={historyError(errors, 'observaciones')}>
              <textarea
                id="observaciones"
                name="observaciones"
                value={form.observaciones}
                onChange={handleChange}
                maxLength={2000}
              />
            </Field>
          </div>

          <div className="form-actions">
            <button type="submit" className="btn" disabled={createPatient.isPending}>
              {createPatient.isPending ? 'Guardando…' : 'Registrar paciente'}
            </button>
          </div>
        </form>
      </div>
    </>
  )
}
