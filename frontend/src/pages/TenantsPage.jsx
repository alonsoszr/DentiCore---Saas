import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient } from '../api/client'
import { fieldErrors, generalError } from '../api/errors'
import { Field } from '../components/Field'
import { PageHeader } from '../components/PageHeader'

const PLAN_LABELS = { basic: 'Básico', pro: 'Pro', enterprise: 'Enterprise' }
const STATUS_LABELS = { active: 'Activa', suspended: 'Suspendida', cancelled: 'Cancelada' }

const EMPTY_FORM = { name: '', slug: '', subscription_plan: 'basic' }

function slugify(text) {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function TenantsPage() {
  const queryClient = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [slugEdited, setSlugEdited] = useState(false)
  const [created, setCreated] = useState(null)

  const tenantsQuery = useQuery({
    queryKey: ['tenants'],
    queryFn: async () => (await apiClient.get('/tenants')).data.data,
  })

  const createTenant = useMutation({
    mutationFn: async (payload) => (await apiClient.post('/tenants', payload)).data.data,
    onSuccess: (tenant) => {
      queryClient.invalidateQueries({ queryKey: ['tenants'] })
      setCreated(tenant)
      setForm(EMPTY_FORM)
      setSlugEdited(false)
      setShowForm(false)
    },
  })

  const handleChange = (event) => {
    const { name, value } = event.target
    if (name === 'name' && !slugEdited) {
      setForm({ ...form, name: value, slug: slugify(value) })
      return
    }
    if (name === 'slug') setSlugEdited(true)
    setForm({ ...form, [name]: value })
  }

  const handleSubmit = (event) => {
    event.preventDefault()
    setCreated(null)
    createTenant.mutate(form)
  }

  const errors = fieldErrors(createTenant.error)
  const tenants = tenantsQuery.data ?? []

  return (
    <>
      <PageHeader title="Clínicas" description="Clínicas registradas en la plataforma.">
        {!showForm && (
          <button type="button" className="btn" onClick={() => setShowForm(true)}>
            Registrar clínica
          </button>
        )}
      </PageHeader>

      {created && (
        <div className="alert alert-success">
          Clínica «{created.name}» registrada. Su código de acceso es <strong>{created.slug}</strong>.
        </div>
      )}

      {showForm && (
        <div className="card">
          <h2>Nueva clínica</h2>
          {generalError(createTenant.error) && (
            <div className="alert alert-error">{generalError(createTenant.error)}</div>
          )}
          <form onSubmit={handleSubmit} noValidate>
            <div className="form-grid">
              <Field label="Nombre" name="name" value={form.name} onChange={handleChange} error={errors.name} />
              <Field
                label="Código de acceso"
                name="slug"
                value={form.slug}
                onChange={handleChange}
                error={errors.slug}
                hint="Lo usará el personal de la clínica para iniciar sesión."
              />
              <Field label="Plan" name="subscription_plan" error={errors.subscription_plan}>
                <select
                  id="subscription_plan"
                  name="subscription_plan"
                  value={form.subscription_plan}
                  onChange={handleChange}
                >
                  {Object.entries(PLAN_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <div className="form-actions">
              <button type="submit" className="btn" disabled={createTenant.isPending}>
                {createTenant.isPending ? 'Guardando…' : 'Guardar'}
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => {
                  setShowForm(false)
                  createTenant.reset()
                }}
              >
                Cancelar
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="card">
        {tenantsQuery.isLoading && <div className="empty">Cargando…</div>}
        {tenantsQuery.isError && <div className="alert alert-error">{generalError(tenantsQuery.error)}</div>}
        {tenantsQuery.isSuccess && tenants.length === 0 && (
          <div className="empty">Aún no hay clínicas registradas.</div>
        )}
        {tenants.length > 0 && (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Código de acceso</th>
                  <th>Plan</th>
                  <th>Estado</th>
                  <th>Registrada</th>
                </tr>
              </thead>
              <tbody>
                {tenants.map((tenant) => (
                  <tr key={tenant.uuid}>
                    <td>{tenant.name}</td>
                    <td className="muted">{tenant.slug}</td>
                    <td>{PLAN_LABELS[tenant.subscription_plan]}</td>
                    <td>
                      <span className={tenant.status === 'active' ? 'badge' : 'badge badge-muted'}>
                        {STATUS_LABELS[tenant.status]}
                      </span>
                    </td>
                    <td className="muted">{new Date(tenant.created_at).toLocaleDateString('es-PE')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  )
}
