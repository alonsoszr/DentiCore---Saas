import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { useClinic } from '../auth/useClinic'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'

const NUMBER_FIELDS = ['discount_cap_pct', 'budget_validity_days', 'portal_cancel_hours']
const NULLABLE_FIELDS = ['phone', 'contact_email', 'budget_terms']
const LOGO_STATUS = {
  pendiente: 'El logotipo está en revisión antivirus; se mostrará cuando termine.',
  infectado: 'El logotipo fue rechazado por el antivirus. Sube otro archivo.',
}

function formFrom(settings) {
  return {
    name: settings.name ?? '',
    address: settings.address ?? '',
    phone: settings.phone ?? '',
    contact_email: settings.contact_email ?? '',
    prices_include_igv: settings.prices_include_igv,
    discount_cap_pct: settings.discount_cap_pct ?? '',
    budget_validity_days: settings.budget_validity_days ?? '',
    portal_cancel_hours: settings.portal_cancel_hours ?? '',
    self_booking_enabled: settings.self_booking_enabled,
    budget_terms: settings.budget_terms ?? '',
  }
}

/** Números como números y opcionales vacíos como null. */
function toPayload(form) {
  const payload = { ...form }
  for (const field of NUMBER_FIELDS) payload[field] = form[field] === '' ? null : Number(form[field])
  for (const field of NULLABLE_FIELDS) payload[field] = form[field] === '' ? null : form[field]
  return payload
}

/**
 * Parámetros de la clínica y logotipo (CUS-05; RF-024, RF-025, RF-026), solo para el
 * Administrador de Clínica. La activación de la IA (RF-027) no se cambia aquí.
 */
export function ClinicSettingsPage() {
  const { slug } = useClinic()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(null)
  const [logo, setLogo] = useState(null)
  const [notice, setNotice] = useState(null)

  const settingsQuery = useQuery({
    queryKey: ['clinic-settings', slug],
    queryFn: async () => (await apiClient.get('/clinic/settings')).data.data,
  })

  const onSaved = (message) => (settings) => {
    queryClient.setQueryData(['clinic-settings', slug], settings)
    setForm(formFrom(settings))
    setNotice(message)
  }

  const save = useMutation({
    mutationFn: async (payload) => (await apiClient.patch('/clinic/settings', payload)).data.data,
    onSuccess: onSaved('Parámetros guardados.'),
  })
  const uploadLogo = useMutation({
    mutationFn: async (file) => {
      const data = new FormData()
      data.append('logo', file)
      return (await apiClient.post('/clinic/logo', data)).data.data
    },
    onSuccess: onSaved('Logotipo recibido.'),
  })

  if (settingsQuery.isLoading) return <div className="empty">Cargando…</div>
  if (settingsQuery.isError) return <div className="alert alert-error">{generalError(settingsQuery.error)}</div>

  const settings = uploadLogo.data ?? save.data ?? settingsQuery.data
  const values = form ?? formFrom(settingsQuery.data)
  const errors = fieldErrors(save.error)
  const logoErrors = fieldErrors(uploadLogo.error)

  const handleChange = (event) => {
    const { name, type, value, checked } = event.target
    setForm({ ...values, [name]: type === 'checkbox' ? checked : value })
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    setNotice(null)
    save.mutate(toPayload(values))
  }

  return (
    <>
      <PageHeader title="Configuración de la clínica" description="Datos de contacto, presupuestos y portal." />

      {notice && <div className="alert alert-success">{notice}</div>}

      <div className="card">
        <h2>Logotipo</h2>
        {settings.logo?.url && <img src={settings.logo.url} alt={`Logotipo de ${settings.name}`} height={64} />}
        {settings.logo && LOGO_STATUS[settings.logo.status] && (
          <p className="muted">{LOGO_STATUS[settings.logo.status]}</p>
        )}
        {generalError(uploadLogo.error) && <div className="alert alert-error">{generalError(uploadLogo.error)}</div>}
        <form
          onSubmit={(event) => {
            event.preventDefault()
            setNotice(null)
            if (logo) uploadLogo.mutate(logo)
          }}
        >
          <Field
            label="Logotipo (PNG o JPG, máximo 1 MB)"
            name="logo"
            type="file"
            accept="image/png,image/jpeg"
            onChange={(event) => setLogo(event.target.files?.[0] ?? null)}
            error={logoErrors.logo}
          />
          <div className="form-actions">
            <button type="submit" className="btn btn-secondary" disabled={!logo || uploadLogo.isPending}>
              Subir logotipo
            </button>
          </div>
        </form>
      </div>

      <div className="card">
        {generalError(save.error) && <div className="alert alert-error">{generalError(save.error)}</div>}
        <form onSubmit={handleSubmit} noValidate>
          <h2>Datos de la clínica</h2>
          <div className="form-grid">
            <Field
              label="Nombre comercial"
              name="name"
              value={values.name}
              onChange={handleChange}
              error={errors.name}
            />
            <Field
              label="Dirección"
              name="address"
              value={values.address}
              onChange={handleChange}
              error={errors.address}
            />
            <Field
              label="Teléfono (opcional)"
              name="phone"
              type="tel"
              value={values.phone}
              onChange={handleChange}
              error={errors.phone}
            />
            <Field
              label="Correo de contacto (opcional)"
              name="contact_email"
              type="email"
              value={values.contact_email}
              onChange={handleChange}
              error={errors.contact_email}
            />
          </div>

          <h3 className="form-section">Presupuestos</h3>
          <div className="form-grid">
            <Field
              label="Tope de descuento (%)"
              name="discount_cap_pct"
              type="number"
              min={0}
              max={100}
              step="0.01"
              value={values.discount_cap_pct}
              onChange={handleChange}
              error={errors.discount_cap_pct}
            />
            <Field
              label="Vigencia del presupuesto (días)"
              name="budget_validity_days"
              type="number"
              min={1}
              max={180}
              value={values.budget_validity_days}
              onChange={handleChange}
              error={errors.budget_validity_days}
            />
            <Field label="Condiciones del presupuesto (opcional)" name="budget_terms" error={errors.budget_terms}>
              <textarea
                id="budget_terms"
                name="budget_terms"
                maxLength={2000}
                rows={4}
                value={values.budget_terms}
                onChange={handleChange}
              />
            </Field>
          </div>
          <label className="checkbox">
            <input
              type="checkbox"
              name="prices_include_igv"
              checked={values.prices_include_igv}
              onChange={handleChange}
            />
            Los precios del catálogo incluyen IGV
          </label>

          <h3 className="form-section">Portal del paciente</h3>
          <div className="form-grid">
            <Field
              label="Plazo para cancelar desde el portal (horas)"
              name="portal_cancel_hours"
              type="number"
              min={0}
              max={72}
              value={values.portal_cancel_hours}
              onChange={handleChange}
              error={errors.portal_cancel_hours}
            />
          </div>
          <label className="checkbox">
            <input
              type="checkbox"
              name="self_booking_enabled"
              checked={values.self_booking_enabled}
              onChange={handleChange}
            />
            Permitir que el paciente reserve citas desde el portal
          </label>
          <p className="muted">Asistencia de IA: {settings.ai_enabled ? 'activada' : 'desactivada'}.</p>

          <div className="form-actions">
            <button type="submit" className="btn" disabled={save.isPending}>
              {save.isPending ? 'Guardando…' : 'Guardar cambios'}
            </button>
          </div>
        </form>
      </div>
    </>
  )
}
