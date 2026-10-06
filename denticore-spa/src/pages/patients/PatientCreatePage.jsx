import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { useClinic } from '../../auth/useClinic'
import { fieldErrors, generalError } from '../../api/errors'
import { Field } from '../../components/Field'
import { PageHeader } from '../../components/PageHeader'
import { DOCUMENT_TYPES, isMinor, today } from './patientIdentity'

const RELATIONSHIPS = { madre: 'Madre', padre: 'Padre', tutor: 'Tutor', curador: 'Curador', otro: 'Otro' }

const EMPTY_FORM = {
  document_type: 'dni',
  document_number: '',
  first_name: '',
  last_name: '',
  birth_date: '',
  sex: '',
  phone: '',
  email: '',
  address: '',
}

const EMPTY_REPRESENTATIVE = {
  document_type: 'dni',
  document_number: '',
  first_name: '',
  last_name: '',
  relationship: 'madre',
  phone: '',
  email: '',
}

/** Opcionales vacíos como null; el representante solo para menores (SDD §4.5). */
function toPayload(form, representative) {
  const payload = {}
  for (const [key, value] of Object.entries(form)) payload[key] = value === '' ? null : value
  if (isMinor(form.birth_date)) {
    payload.representative = { ...representative, email: representative.email || null, valid_from: today() }
  }
  return payload
}

/**
 * Registro de paciente (CUS-14, SRS §11.3). Pantalla funcional de MS-01; el diseño definitivo y
 * los antecedentes médicos (con consentimiento) llegan con TASK-041.
 */
export function PatientCreatePage() {
  const { slug, appPath } = useClinic()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(EMPTY_FORM)
  const [representative, setRepresentative] = useState(EMPTY_REPRESENTATIVE)
  const createPatient = useMutation({
    mutationFn: async (payload) => (await apiClient.post('/patients', payload)).data.data,
    onSuccess: (patient) => {
      queryClient.invalidateQueries({ queryKey: ['patients', slug] })
      navigate(appPath(`/pacientes/${patient.id}`), { state: { created: true } })
    },
  })

  const handleChange = (event) => setForm({ ...form, [event.target.name]: event.target.value })
  const handleRepresentativeChange = (event) =>
    setRepresentative({ ...representative, [event.target.name.replace('representative_', '')]: event.target.value })

  const handleSubmit = (event) => {
    event.preventDefault()
    createPatient.mutate(toPayload(form, representative))
  }

  const errors = fieldErrors(createPatient.error)
  // RF-056: si el documento ya existe, la API indica la ficha existente.
  const existingPatientId = createPatient.error?.response?.data?.existing_patient_id
  const minor = isMinor(form.birth_date)

  return (
    <>
      <PageHeader
        title="Registrar paciente"
        description="El documento, el teléfono y la dirección se guardan cifrados."
      >
        <Link to={appPath('/pacientes')} className="btn btn-secondary">
          Volver
        </Link>
      </PageHeader>

      <div className="card">
        {existingPatientId && (
          <div className="alert alert-error">
            Ya existe una ficha con este documento.{' '}
            <Link to={appPath(`/pacientes/${existingPatientId}`)}>Ver la ficha existente</Link>
          </div>
        )}
        {generalError(createPatient.error) && !existingPatientId && (
          <div className="alert alert-error">{generalError(createPatient.error)}</div>
        )}
        <form onSubmit={handleSubmit} noValidate autoComplete="off">
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
              autoComplete="off"
              value={form.document_number}
              onChange={handleChange}
              error={errors.document_number}
              maxLength={12}
            />
            <Field
              label="Nombres"
              name="first_name"
              autoComplete="off"
              value={form.first_name}
              onChange={handleChange}
              error={errors.first_name}
            />
            <Field
              label="Apellidos"
              name="last_name"
              autoComplete="off"
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
              autoComplete="off"
              type="tel"
              value={form.phone}
              onChange={handleChange}
              error={errors.phone}
              hint="Celular de 9 dígitos (9…) o número internacional (+…)."
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
            <Field
              label="Dirección (opcional)"
              name="address"
              autoComplete="off"
              value={form.address}
              onChange={handleChange}
              error={errors.address}
            />
          </div>

          {minor && (
            <>
              <h3 className="form-section">Representante legal (paciente menor de 18 años)</h3>
              {errors.representative && <div className="alert alert-error">{errors.representative}</div>}
              <div className="form-grid">
                <Field
                  label="Tipo de documento del representante"
                  name="representative_document_type"
                  error={errors['representative.document_type']}
                >
                  <select
                    id="representative_document_type"
                    name="representative_document_type"
                    value={representative.document_type}
                    onChange={handleRepresentativeChange}
                  >
                    {Object.entries(DOCUMENT_TYPES).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field
                  label="Número de documento del representante"
                  name="representative_document_number"
                  value={representative.document_number}
                  onChange={handleRepresentativeChange}
                  error={errors['representative.document_number']}
                />
                <Field
                  label="Nombres del representante"
                  name="representative_first_name"
                  value={representative.first_name}
                  onChange={handleRepresentativeChange}
                  error={errors['representative.first_name']}
                />
                <Field
                  label="Apellidos del representante"
                  name="representative_last_name"
                  value={representative.last_name}
                  onChange={handleRepresentativeChange}
                  error={errors['representative.last_name']}
                />
                <Field
                  label="Parentesco"
                  name="representative_relationship"
                  error={errors['representative.relationship']}
                >
                  <select
                    id="representative_relationship"
                    name="representative_relationship"
                    value={representative.relationship}
                    onChange={handleRepresentativeChange}
                  >
                    {Object.entries(RELATIONSHIPS).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field
                  label="Teléfono del representante"
                  name="representative_phone"
                  type="tel"
                  value={representative.phone}
                  onChange={handleRepresentativeChange}
                  error={errors['representative.phone']}
                />
                <Field
                  label="Correo del representante (opcional)"
                  name="representative_email"
                  type="email"
                  value={representative.email}
                  onChange={handleRepresentativeChange}
                  error={errors['representative.email']}
                />
              </div>
            </>
          )}

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
