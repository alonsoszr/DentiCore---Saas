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
    select: (users) => users.filter((u) => u.role === 'patient' && u.is_active),
  })

  const createPatient = useMutation({
    mutationFn: async (payload) => (await apiClient.post('/patients', payload)).data.data,
    onSuccess: (patient) => {
      queryClient.invalidateQueries({ queryKey: ['patients'] })
      navigate(`/pacientes/${patient.uuid}`, { state: { created: true } })
    },
  })

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })

  const handleSubmit = (event) => {
    event.preventDefault()
    // Campos opcionales vacíos se envían como null (la API los valida como nullable).
    const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]))
    createPatient.mutate(payload)
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
        <form onSubmit={handleSubmit} noValidate>
          <div className="form-grid">
            <Field label="Nombres" name="first_name" value={form.first_name} onChange={handleChange} error={errors.first_name} />
            <Field label="Apellidos" name="last_name" value={form.last_name} onChange={handleChange} error={errors.last_name} />
            <Field
              label="Documento de identidad (DNI)"
              name="document_id"
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
              type="tel"
              value={form.phone}
              onChange={handleChange}
              error={errors.phone}
              maxLength={20}
            />
            <Field
              label="Correo electrónico (opcional)"
              name="email"
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
