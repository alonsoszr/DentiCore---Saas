import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, Navigate } from 'react-router-dom'
import { apiClient } from '../../api/client'
import { fieldErrors, generalError } from '../../api/errors'
import { useAuth } from '../../auth/useAuth'
import { useClinic } from '../../auth/useClinic'
import { Alert } from '../../components/Alert'
import { Field } from '../../components/Field'
import { formatDateTime } from '../../ui/format'
import { FIELDS, SECTION_TITLES, SECTIONS_BY_ROLE, VARIABLE_ROLES, dentitionFor, missingLabel } from './labels'
import { RiskPurposeAlert } from './RiskPurposeAlert'
import { useRiskPurpose, useVariableSets } from './useRisk'

/** Valor del formulario desde el registro: números y booleanos como texto para los controles. */
function initialValues(set, sections) {
  return Object.fromEntries(
    sections.map((section) => [
      section,
      Object.fromEntries(
        FIELDS[section].map(({ key }) => {
          const value = set?.[section]?.[key]
          return [key, value === null || value === undefined ? '' : String(value)]
        }),
      ),
    ]),
  )
}

/** ¿Se pide el campo a este paciente? La estructura familiar solo a menores y CPOD/ceod según la dentición (DI-20). */
function appliesTo(field, patient) {
  if (field.minorOnly && !patient.is_minor) return false
  return !field.dentitions || field.dentitions.includes(dentitionFor(patient.age_years))
}

/** Cuerpo de la API: números, booleanos y el informante según la edad (SDD §2.14.2). */
function toPayload(values, sections, patient) {
  const isMinor = patient.is_minor
  return Object.fromEntries(
    sections.map((section) => {
      const entries = FIELDS[section]
        .filter((field) => !field.minorOnly || isMinor)
        .map((field) => {
          const { key, kind } = field
          const raw = values[section][key]
          if (raw === '' || !appliesTo(field, patient)) return [key, null]
          if (kind === 'number') return [key, Number(raw)]
          if (kind === 'boolean') return [key, raw === 'true']
          return [key, raw]
        })
      const payload = Object.fromEntries(entries)
      if (section === 'sociodemographic') payload.respondent = isMinor ? 'representante' : 'titular'
      return [section, payload]
    }),
  )
}

/** Valor legible de un campo de solo lectura. */
function displayValue(field, value) {
  if (value === null || value === undefined || value === '') return '—'
  if (field.kind === 'boolean') return value ? 'Sí' : 'No'
  if (field.kind === 'select') return field.options.find(([option]) => option === value)?.[1] ?? value
  return String(value)
}

/**
 * Variables de riesgo (CUS-54; RF-161, RF-162, RF-164; SDD §2.14.2): el odontólogo registra las
 * clínicas y los hábitos y la recepción los datos sociodemográficos; el resto se muestra en solo
 * lectura. El informante (titular o representante) y la estructura familiar dependen de la edad.
 * Un registro ya usado en una predicción no cambia: guardar crea uno nuevo que lo reemplaza.
 */
export function RiskVariablesPage({ patient }) {
  const { user } = useAuth()
  const { slug, appPath } = useClinic()
  const queryClient = useQueryClient()
  const editable = SECTIONS_BY_ROLE[user?.role] ?? []
  const canSee = VARIABLE_ROLES.includes(user?.role)
  const sets = useVariableSets(patient.id, canSee)
  const purpose = useRiskPurpose(patient.id, canSee)
  const formRef = useRef(null)
  const latest = sets.data?.[0] ?? null
  const [form, setForm] = useState(null)
  const [notice, setNotice] = useState(null)
  const values = form ?? initialValues(latest, editable)

  const save = useMutation({
    mutationFn: async (payload) =>
      latest
        ? await apiClient.patch(`/risk-variable-sets/${latest.id}`, payload)
        : await apiClient.post(`/patients/${patient.id}/risk-variable-sets`, payload),
    onSuccess: (response) => {
      const saved = response.data.data
      setNotice(
        latest && saved.id !== latest.id
          ? 'Se creó un nuevo registro: el anterior ya se usó en una predicción y no se modifica.'
          : 'Variables guardadas.',
      )
      setForm(null)
      queryClient.invalidateQueries({ queryKey: ['risk-variable-sets', slug, patient.id] })
    },
  })

  // Tras un 422 el foco va al primer campo con error, para leerlo y corregirlo de inmediato.
  useEffect(() => {
    if (save.error) formRef.current?.querySelector('[aria-invalid="true"]')?.focus()
  }, [save.error])

  if (!canSee) return <Navigate to={appPath(`/pacientes/${patient.id}/riesgo`)} replace />
  if (sets.isLoading) return <div className="card empty">Cargando…</div>
  if (sets.isError) return <div className="alert alert-error">{generalError(sets.error)}</div>

  const errors = fieldErrors(save.error)
  const setValue = (section, key, value) => {
    setNotice(null)
    setForm({ ...values, [section]: { ...values[section], [key]: value } })
  }
  const handleSubmit = (event) => {
    event.preventDefault()
    save.mutate(toPayload(values, editable, patient))
  }
  const visibleFields = (section) => FIELDS[section].filter((field) => appliesTo(field, patient))

  return (
    <>
      <section className="card" aria-labelledby="risk-variables-title">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 id="risk-variables-title" className="m-0">
            Variables de riesgo
          </h2>
          {user?.role === 'dentist' && (
            <Link to={appPath(`/pacientes/${patient.id}/riesgo`)} className="btn btn-secondary">
              Ir al cálculo
            </Link>
          )}
        </div>
        <p className="muted">
          {patient.is_minor ? 'Informa el representante del paciente.' : 'Informa el propio paciente.'}{' '}
          {latest
            ? `Registro del ${formatDateTime(latest.created_at)}${latest.locked_at ? ', ya usado en una predicción' : ''}.`
            : 'Aún no hay variables registradas.'}
        </p>
        {latest && latest.missing.length > 0 && (
          <Alert tone="info" title="Pendiente para calcular el riesgo">
            {latest.missing.map(missingLabel).join(', ')}.
          </Alert>
        )}
        {latest?.is_complete && <Alert tone="success">El registro está completo para calcular el riesgo.</Alert>}
        {purpose.allowed === false && <RiskPurposeAlert patient={patient} className="mt-3" />}
      </section>

      {editable.length > 0 && !purpose.isLoading && purpose.allowed !== false && (
        <form ref={formRef} onSubmit={handleSubmit} noValidate className="card" aria-label="Registrar variables">
          {notice && (
            <Alert tone="success" className="mb-4">
              {notice}
            </Alert>
          )}
          {generalError(save.error) && (
            <Alert tone="error" className="mb-4">
              {generalError(save.error)}
            </Alert>
          )}
          {editable.map((section) => (
            <fieldset key={section} className="m-0 mb-4 border-0 p-0">
              <legend className="text-title-md mb-3">{SECTION_TITLES[section]}</legend>
              <div className="form-grid">
                {visibleFields(section).map((field) => {
                  const name = `${section}.${field.key}`
                  const id = `${section}-${field.key}`
                  const error = errors[name]
                  const describedBy = error ? `${id}-error` : field.hint ? `${id}-hint` : undefined
                  return (
                    <Field key={field.key} label={field.label} name={id} error={error} hint={field.hint}>
                      {field.kind === 'number' ? (
                        <input
                          id={id}
                          type="number"
                          inputMode="decimal"
                          min={field.min}
                          max={field.max}
                          step={field.step}
                          value={values[section][field.key]}
                          aria-invalid={error ? true : undefined}
                          aria-describedby={describedBy}
                          onChange={(event) => setValue(section, field.key, event.target.value)}
                        />
                      ) : (
                        <select
                          id={id}
                          value={values[section][field.key]}
                          aria-invalid={error ? true : undefined}
                          aria-describedby={describedBy}
                          onChange={(event) => setValue(section, field.key, event.target.value)}
                        >
                          <option value="">Seleccione…</option>
                          {(field.kind === 'boolean'
                            ? [
                                ['true', 'Sí'],
                                ['false', 'No'],
                              ]
                            : field.options
                          ).map(([option, label]) => (
                            <option key={option} value={option}>
                              {label}
                            </option>
                          ))}
                        </select>
                      )}
                    </Field>
                  )
                })}
              </div>
            </fieldset>
          ))}
          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              {save.isPending ? 'Guardando…' : 'Guardar variables'}
            </button>
          </div>
        </form>
      )}

      {Object.keys(FIELDS)
        .filter((section) => !editable.includes(section))
        .map((section) => (
          <section key={section} className="card" aria-labelledby={`risk-${section}-title`}>
            <h2 id={`risk-${section}-title`}>{SECTION_TITLES[section]}</h2>
            <dl className="risk-facts">
              {visibleFields(section).map((field) => (
                <div key={field.key}>
                  <dt>{field.label}</dt>
                  <dd>{displayValue(field, latest?.[section]?.[field.key])}</dd>
                </div>
              ))}
            </dl>
          </section>
        ))}
    </>
  )
}
